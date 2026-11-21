<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

class InitialSchoolAdmin
{
    public function initialize(string $manifest): void
    {
        $data = $this->authorizedManifest($manifest);

        DB::transaction(function () use ($data): void {
            // Insert a fresh school only. The unique school code is the concurrency boundary.
            if (School::where('code', $data['school_code'])->exists() || DB::table('initial_school_admin_proofs')->where('id', $data['proof_id'])->exists()) {
                throw new AuthorizationException('The school or authorization has already been used.');
            }

            $school = School::create(['code' => $data['school_code'], 'name' => 'Synthetic staging school', 'data_stage' => 'demo', 'access_state' => 'provisional', 'demo_ends_at' => now()->addDay()]);
            $operator = User::create(['name' => 'Offline staging bootstrap authority', 'password' => bin2hex(random_bytes(32)), 'status' => 'inactive']);
            $admin = User::create(['name' => 'Synthetic staging School Admin', 'password' => bin2hex(random_bytes(32)), 'status' => 'active', 'activation_required' => true, 'must_change_password' => true]);
            $membership = Membership::create(['school_id' => $school->id, 'user_id' => $admin->id, 'role' => Role::SchoolAdmin, 'login_identifier' => 'STAGE-ADMIN', 'status' => 'active']);

            DB::table('initial_school_admin_proofs')->insert([
                'id' => $data['proof_id'],
                'school_id' => $school->id,
                'user_id' => $admin->id,
                'membership_id' => $membership->id,
                'secret_hash' => $data['secret_hash'],
                'expires_at' => now()->setTimestamp($data['expires_at']),
            ]);

            AuditEvent::create(['school_id' => $school->id, 'actor_user_id' => $operator->id, 'action' => 'staging.initial_admin.issued', 'resource_id' => $membership->id, 'request_id' => (string) Str::ulid()]);
        }, 3);
    }

    public function reissue(string $manifest): void
    {
        $data = $this->authorizedManifest($manifest, reissue: true);

        DB::transaction(function () use ($data): void {
            $school = School::where('code', $data['school_code'])->lockForUpdate()->first();
            if (! $school || ! $this->eligibleSchool($school) || $data['expires_at'] <= now()->timestamp) {
                throw new AuthorizationException('The pending staging school is unavailable.');
            }

            $proof = DB::table('initial_school_admin_proofs')->where('school_id', $school->id)->where('id', $data['previous_proof_id'])->lockForUpdate()->first();
            if (! $proof || $proof->consumed_at || hash_equals($proof->secret_hash, $data['secret_hash'])) {
                throw new AuthorizationException('The pending activation proof is unavailable.');
            }

            [$user, $member] = $this->lockedPendingAdmin($school, $proof);
            if (! $this->eligibleAdmin($user, $member)) {
                throw new AuthorizationException('The pending staging School Admin is unavailable.');
            }

            $issuance = AuditEvent::where('school_id', $school->id)->where('resource_id', $member->id)
                ->where('action', 'staging.initial_admin.issued')->sole();
            $actor = User::whereKey($issuance->actor_user_id)->firstOrFail();
            if ($actor->status !== 'inactive' || $actor->is_platform_admin || $actor->memberships()->exists()) {
                throw new AuthorizationException('The original offline authority is unavailable.');
            }

            if (AuditEvent::where('school_id', $school->id)->where('action', 'staging.initial_admin.reissued')
                ->where('details->retired_proof_fingerprint', hash('sha256', $data['proof_id']))->exists()) {
                throw new AuthorizationException('A retired proof ID cannot be reused.');
            }

            // Replacing the locked proof invalidates the old ID and consumes this manifest.
            DB::table('initial_school_admin_proofs')->where('id', $proof->id)->update([
                'id' => $data['proof_id'],
                'secret_hash' => $data['secret_hash'],
                'expires_at' => now()->setTimestamp($data['expires_at']),
            ]);
            AuditEvent::create([
                'school_id' => $school->id,
                'actor_user_id' => $actor->id,
                'action' => 'staging.initial_admin.reissued',
                'resource_id' => $member->id,
                'request_id' => (string) Str::ulid(),
                'details' => ['retired_proof_fingerprint' => hash('sha256', $proof->id)],
            ]);
        }, 3);
    }

    private function authorizedManifest(string $manifest, bool $reissue = false): array
    {
        $approval = config('staging-bootstrap.approval_digest');
        if (! app()->runningInConsole() || ! app()->environment(['staging', 'testing']) || ! is_string($approval) || ! hash_equals($approval, hash('sha256', $manifest))) {
            throw new AuthorizationException('Bootstrap authorization is unavailable.');
        }

        try {
            $data = json_decode($manifest, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AuthorizationException('Invalid bootstrap authorization.');
        }

        if (! is_array($data) || count($data) !== ($reissue ? 5 : 4)
            || ! is_string($data['school_code'] ?? null) || ! preg_match('/\AEDU-STAGE-[A-Z0-9]{4,22}\z/', $data['school_code'])
            || ! is_string($data['proof_id'] ?? null) || ! Str::isUlid($data['proof_id'])
            || ($reissue && (! is_string($data['previous_proof_id'] ?? null) || ! Str::isUlid($data['previous_proof_id']) || $data['previous_proof_id'] === $data['proof_id']))
            || ! is_string($data['secret_hash'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/', $data['secret_hash'])
            || ! is_int($data['expires_at'] ?? null) || $data['expires_at'] <= now()->timestamp || $data['expires_at'] > now()->addMinutes(30)->timestamp) {
            throw new AuthorizationException('Invalid or expired bootstrap authorization.');
        }

        return $data;
    }

    public function consume(School $school, array $data, string $requestId, ?string $signedInUser): array
    {
        // The caller holds the school lock for both proof families.
        $proof = DB::table('initial_school_admin_proofs')->where('school_id', $school->id)->where('id', $data['proof_id'])->lockForUpdate()->first();
        if (! $proof || $proof->consumed_at || now()->greaterThanOrEqualTo($proof->expires_at)
            || ! hash_equals($proof->secret_hash, hash('sha256', $data['secret']))
            || ($signedInUser !== null && $signedInUser !== $proof->user_id)
            || ! $this->eligibleSchool($school)) {
            $this->deny();
        }

        [$user, $member] = $this->lockedPendingAdmin($school, $proof);
        if (! $this->eligibleAdmin($user, $member)) {
            $this->deny();
        }

        $user->update(['password' => $data['password'], 'activation_required' => false, 'must_change_password' => false]);
        DB::table('initial_school_admin_proofs')->where('id', $proof->id)->update(['consumed_at' => now()]);
        AuditEvent::create(['school_id' => $school->id, 'actor_user_id' => $user->id, 'action' => 'staging.initial_admin.activated', 'resource_id' => $member->id, 'request_id' => $requestId]);

        return ['activated' => true, 'login_required' => true];
    }

    private function eligibleSchool(School $school): bool
    {
        return $school->data_stage === 'demo' && $school->access_state === 'provisional' && ($school->demo_ends_at?->isFuture() ?? false);
    }

    private function lockedPendingAdmin(School $school, object $proof): array
    {
        $user = User::whereKey($proof->user_id)->lockForUpdate()->first();
        $member = Membership::where('school_id', $school->id)->whereKey($proof->membership_id)->lockForUpdate()->first();

        return [$user, $member];
    }

    private function eligibleAdmin(?User $user, ?Membership $member): bool
    {
        return $user && $member && $member->user_id === $user->id && $member->role === Role::SchoolAdmin && $member->status === 'active'
            && $member->login_identifier === 'STAGE-ADMIN' && ! $user->is_platform_admin && $user->status === 'active' && $user->activation_required && $user->must_change_password;
    }

    private function deny(): never
    {
        throw ValidationException::withMessages(['activation' => 'Activation proof is invalid, expired or unavailable.']);
    }
}
