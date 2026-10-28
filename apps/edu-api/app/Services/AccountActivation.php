<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountActivation
{
    public function provision(Membership $actor, array $data, string $requestId, ?string $key): array
    {
        return DB::transaction(function () use ($actor, $data, $requestId, $key) {
            $result = app(ProvisionStaff::class)->execute($actor, $data, $requestId, $key);
            $target = Membership::whereKey($result['membership_id'])->firstOrFail();
            if ($result['replayed']) {
                abort_unless($target->user->activation_required, 409);

                return ['membership_id' => $target->id, 'activation_status' => 'pending', 'replayed' => true];
            }
            $target->user->update(['activation_required' => true]);

            return ['membership_id' => $target->id, 'activation_status' => 'pending', 'replayed' => false, 'local_handover' => $this->issue($actor, $target->id, $requestId)];
        }, 3);
    }

    private function fingerprint(Membership $member): string
    {
        $u = $member->user;

        return hash('sha256', json_encode([$member->id, $member->school_id, $member->user_id, $member->role->value, $member->login_identifier, $member->status, $u->status, $u->password, $u->is_platform_admin, $u->must_change_password, $u->activation_required], JSON_THROW_ON_ERROR));
    }

    private function audit(string $school, string $actor, string $action, string $resource, string $requestId): void
    {
        AuditEvent::create(['school_id' => $school, 'actor_user_id' => $actor, 'action' => $action, 'resource_id' => $resource, 'request_id' => $requestId]);
    }

    public function issue(Membership $actor, string $targetId, string $requestId): array
    {
        app(SchoolAccess::class)->requireAdmin($actor);

        return DB::transaction(function () use ($actor, $targetId, $requestId) {
            School::whereKey($actor->school_id)->lockForUpdate()->firstOrFail();
            $target = Membership::where('school_id', $actor->school_id)->whereKey($targetId)->firstOrFail();
            User::whereIn('id', [$actor->user_id, $target->user_id])->orderBy('id')->lockForUpdate()->get();
            $current = Membership::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $target = Membership::whereKey($target->id)->lockForUpdate()->with('user')->firstOrFail();
            app(SchoolAccess::class)->requireAdmin($current);
            abort_unless(app(SchoolAccess::class)->canWrite($current), 403);
            abort_unless($target->status === 'active' && in_array($target->role->value, ['teacher', 'principal'], true) && $target->user->status === 'active' && ! $target->user->is_platform_admin && $target->user->must_change_password && $target->user->activation_required, 403);
            $prior = DB::table('account_activations')->where('school_id', $actor->school_id)->where('membership_id', $target->id)->whereNull('consumed_at')->whereNull('invalidated_at');
            if ($prior->update(['invalidated_at' => now(), 'updated_at' => now()])) {
                $this->audit($actor->school_id, $current->user_id, 'activation.invalidated', $target->id, $requestId);
            }
            $id = (string) Str::ulid();
            $secret = bin2hex(random_bytes(32));
            $expiry = now()->addMinutes(30);
            DB::table('account_activations')->insert(['id' => $id, 'school_id' => $current->school_id, 'user_id' => $target->user_id, 'membership_id' => $target->id, 'issuer_membership_id' => $current->id, 'purpose' => 'first_activation', 'secret_hash' => hash('sha256', $secret), 'authority_hash' => $this->fingerprint($current), 'subject_hash' => $this->fingerprint($target), 'expires_at' => $expiry, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($current->school_id, $current->user_id, 'activation.issued', $id, $requestId);

            return app(LocalActivationDelivery::class)->handover(['proof_id' => $id, 'secret' => $secret, 'expires_at' => $expiry->toIso8601String()]);
        }, 3);
    }

    public function consume(array $data, string $requestId, ?string $signedInUser = null): array
    {
        Validator::make($data, ['school_code' => ['required', 'string', 'max:32'], 'proof_id' => ['required', 'ulid'], 'secret' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]+\z/'], 'password' => ['required', 'string', 'min:12', 'max:72', 'confirmed', function ($attribute, $value, $fail): void {
            if (strlen($value) > 72) {
                $fail('Password must not exceed 72 bytes.');
            }
        }], 'password_confirmation' => ['required', 'string', 'max:72']])->validate();
        if (array_diff(array_keys($data), ['school_code', 'proof_id', 'secret', 'password', 'password_confirmation'])) {
            throw ValidationException::withMessages(['payload' => 'Unknown activation field.']);
        }

        return DB::transaction(function () use ($data, $requestId, $signedInUser) {
            $deny = fn () => throw ValidationException::withMessages(['activation' => 'Activation proof is invalid, expired or unavailable. Ask your School Admin to reissue it.']);
            $school = School::where('code', $data['school_code'])->lockForUpdate()->first();
            $proof = $school ? DB::table('account_activations')->where('school_id', $school->id)->where('id', $data['proof_id'])->first() : null;
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
            $proof = DB::table('account_activations')->where('id', $proof->id)->lockForUpdate()->first();
            if (! $target || ! $issuer || $proof->purpose !== 'first_activation' || $proof->consumed_at || $proof->invalidated_at || now()->greaterThanOrEqualTo($proof->expires_at) || ! hash_equals($proof->secret_hash, hash('sha256', $data['secret'])) || ($signedInUser && $signedInUser !== $proof->user_id) || $target->user_id !== $proof->user_id || $target->status !== 'active' || ! in_array($target->role->value, ['teacher', 'principal'], true) || $target->user->status !== 'active' || $target->user->is_platform_admin || ! $target->user->activation_required || ! $target->user->must_change_password || ! hash_equals($proof->subject_hash, $this->fingerprint($target)) || ! hash_equals($proof->authority_hash, $this->fingerprint($issuer))) {
                $deny();
            }
            try {
                app(SchoolAccess::class)->requireAdmin($issuer);
            } catch (AuthorizationException) {
                $deny();
            }
            if (! app(SchoolAccess::class)->canWrite($issuer)) {
                $deny();
            }
            $user = $target->user;
            $user->password = $data['password'];
            $user->activation_required = false;
            $user->must_change_password = false;
            $user->saveQuietly(); // Consumption and invalidation are already atomic under the locks above.
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('account_activations')->where('id', $proof->id)->update(['consumed_at' => now(), 'updated_at' => now()]);
            DB::table('account_activations')->where('user_id', $user->id)->where('id', '!=', $proof->id)->whereNull('consumed_at')->whereNull('invalidated_at')->update(['invalidated_at' => now(), 'updated_at' => now()]);
            $this->audit($school->id, $user->id, 'activation.completed', $proof->id, $requestId);

            return ['activated' => true, 'login_required' => true];
        }, 3);
    }
}
