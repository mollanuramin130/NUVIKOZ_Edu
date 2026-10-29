<?php

namespace App\Services;

use App\Http\Requests\ActivationRequest;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountRecovery
{
    private function fingerprint(Membership $member): string
    {
        $u = $member->user;

        return hash('sha256', json_encode([$member->id, $member->school_id, $member->user_id, $member->role->value, $member->login_identifier, $member->status, $u->status, $u->password, $u->is_platform_admin, $u->must_change_password, $u->activation_required], JSON_THROW_ON_ERROR));
    }

    private function eligible(Membership $member): bool
    {
        return $member->status === 'active' && $member->user->status === 'active' && ! $member->user->is_platform_admin && ! $member->user->activation_required && ! $member->user->must_change_password && ! in_array($member->school->access_state, ['suspended', 'archived'], true);
    }

    private function throttle(string $key, int $limit): void
    {
        abort_if(RateLimiter::tooManyAttempts($key, $limit), 429);
        RateLimiter::hit($key, 900);
    }

    private function audit(string $school, string $actor, string $action, string $resource, string $requestId): void
    {
        AuditEvent::create(['school_id' => $school, 'actor_user_id' => $actor, 'action' => $action, 'resource_id' => $resource, 'request_id' => $requestId]);
    }

    public function request(array $data): array
    {
        Validator::make($data, ['school_code' => ['required', 'string', 'max:32'], 'identifier' => ['required', 'string', 'max:80']])->validate();

        // No verified-contact model exists. Never guess identity from a shared phone or deliver
        // to an unverified address. Identical result and no identity query for every public input.
        return ['message' => 'If recovery is available, follow the authorized instructions. Otherwise contact your School Admin for identity verification.'];
    }

    public function issue(Membership $actor, string $targetId, array $verification, string $requestId): array
    {
        app(SchoolAccess::class)->requireAdmin($actor);
        Validator::make($verification, ['verification_method' => ['required', 'in:in_person_registered_record'], 'verification_reference' => ['required', 'string', 'min:16', 'max:80', 'regex:/\A[A-Za-z0-9_-]+\z/'], 'identity_verified' => ['required', 'accepted']])->validate();
        abort_unless(array_diff(array_keys($verification), ['verification_method', 'verification_reference', 'identity_verified']) === [], 422);

        return DB::transaction(function () use ($actor, $targetId, $verification, $requestId) {
            School::whereKey($actor->school_id)->lockForUpdate()->firstOrFail();
            $target = Membership::where('school_id', $actor->school_id)->whereKey($targetId)->firstOrFail();
            User::whereIn('id', [$actor->user_id, $target->user_id])->orderBy('id')->lockForUpdate()->get();
            $members = Membership::where('school_id', $actor->school_id)->whereIn('id', [$actor->id, $target->id])->orderBy('id')->lockForUpdate()->with(['school', 'user'])->get()->keyBy('id');
            $current = $members->get($actor->id);
            $target = $members->get($target->id);
            app(SchoolAccess::class)->requireAdmin($current);
            abort_unless($this->eligible($target) && $current->user_id !== $target->user_id, 403);
            // Recovery is a narrow security exception to expired write entitlement.
            $this->throttle('recovery-issuer:'.$current->id, 10);
            $this->throttle('recovery-subject:'.$target->id, 3);
            $prior = DB::table('account_recoveries')->where('user_id', $target->user_id)->whereNull('consumed_at')->whereNull('invalidated_at');
            if ($prior->update(['invalidated_at' => now(), 'updated_at' => now()])) {
                $this->audit($current->school_id, $current->user_id, 'recovery.invalidated', $target->id, $requestId);
            }
            $id = (string) Str::ulid();
            $secret = bin2hex(random_bytes(32));
            $expiry = now()->addMinutes(15);
            DB::table('account_recoveries')->insert(['id' => $id, 'school_id' => $current->school_id, 'user_id' => $target->user_id, 'membership_id' => $target->id, 'issuer_membership_id' => $current->id, 'purpose' => 'password_recovery', 'secret_hash' => hash('sha256', $secret), 'authority_hash' => $this->fingerprint($current), 'subject_hash' => $this->fingerprint($target), 'verification_method' => $verification['verification_method'], 'verification_reference_hash' => hash('sha256', $verification['verification_reference']), 'verified_at' => now(), 'expires_at' => $expiry, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($current->school_id, $current->user_id, 'recovery.assisted_issued', $id, $requestId);

            // Reuses only the ephemeral, nonproduction delivery boundary, never activation proofs.
            return app(LocalActivationDelivery::class)->handover(['proof_id' => $id, 'secret' => $secret, 'expires_at' => $expiry->toIso8601String()]);
        }, 3);
    }

    public function consume(array $data, string $requestId, ?string $signedInUser = null): array
    {
        Validator::make($data, (new ActivationRequest)->rules())->validate();
        abort_unless(array_diff(array_keys($data), ['school_code', 'proof_id', 'secret', 'password', 'password_confirmation']) === [], 422);
        $this->throttle('recovery-consume:'.hash('sha256', $data['school_code'].'|'.$data['proof_id']), 5);

        return DB::transaction(function () use ($data, $requestId, $signedInUser) {
            $deny = fn () => throw ValidationException::withMessages(['recovery' => 'Recovery proof is invalid, expired or unavailable. Contact your School Admin.']);
            $school = School::where('code', $data['school_code'])->lockForUpdate()->first();
            $proof = $school ? DB::table('account_recoveries')->where('school_id', $school->id)->where('id', $data['proof_id'])->first() : null;
            if (! $proof) {
                $deny();
            }
            $issuer = Membership::where('school_id', $school->id)->whereKey($proof->issuer_membership_id)->first();
            if (! $issuer) {
                $deny();
            }
            User::whereIn('id', [$issuer->user_id, $proof->user_id])->orderBy('id')->lockForUpdate()->get();
            $members = Membership::where('school_id', $school->id)->whereIn('id', [$proof->membership_id, $proof->issuer_membership_id])->orderBy('id')->lockForUpdate()->with(['user', 'school'])->get()->keyBy('id');
            $target = $members->get($proof->membership_id);
            $issuer = $members->get($proof->issuer_membership_id);
            $proof = DB::table('account_recoveries')->where('id', $proof->id)->lockForUpdate()->first();
            if (! $target || ! $issuer || $proof->purpose !== 'password_recovery' || $proof->consumed_at || $proof->invalidated_at || now()->greaterThanOrEqualTo($proof->expires_at) || ! hash_equals($proof->secret_hash, hash('sha256', $data['secret'])) || ($signedInUser && $signedInUser !== $proof->user_id) || $target->user_id !== $proof->user_id || ! $this->eligible($target) || ! hash_equals($proof->subject_hash, $this->fingerprint($target)) || ! hash_equals($proof->authority_hash, $this->fingerprint($issuer))) {
                $deny();
            }
            try {
                app(SchoolAccess::class)->requireAdmin($issuer);
            } catch (AuthorizationException) {
                $deny();
            }
            $user = $target->user;
            $user->password = $data['password'];
            $user->saveQuietly();
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('account_recoveries')->where('id', $proof->id)->update(['consumed_at' => now(), 'updated_at' => now()]);
            // Credential change also invalidates proofs issued by this identity in any school.
            foreach (['account_activations', 'account_recoveries'] as $table) {
                DB::table($table)->whereNull('consumed_at')->whereNull('invalidated_at')->where(fn ($q) => $q->where('user_id', $user->id)->orWhereIn('issuer_membership_id', DB::table('memberships')->select('id')->where('user_id', $user->id)))->update(['invalidated_at' => now(), 'updated_at' => now()]);
            }
            $this->audit($school->id, $user->id, 'recovery.completed', $proof->id, $requestId);

            return ['reset' => true, 'login_required' => true];
        }, 3);
    }
}
