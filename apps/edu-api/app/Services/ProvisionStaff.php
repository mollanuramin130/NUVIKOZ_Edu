<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProvisionStaff
{
    public function execute(Membership $actor, array $data, string $requestId, ?string $key = null): array
    {
        app(SchoolAccess::class)->requireAdmin($actor);
        Validator::make($data, [
            'name' => ['required', 'string', 'max:160'],
            'login_identifier' => ['required', 'string', 'max:80', 'regex:/\A[A-Za-z0-9_-]+\z/'],
            'role' => ['required', 'in:teacher,principal'],
        ])->validate();
        if (array_diff(array_keys($data), ['name', 'login_identifier', 'role'])) {
            throw ValidationException::withMessages(['payload' => 'Unknown provisioning field.']);
        }
        app(SchoolAccess::class)->requireAdmin($actor);
        abort_unless(app(SchoolAccess::class)->canWrite($actor), 403, 'School is read-only.');

        return DB::transaction(function () use ($actor, $data, $requestId, $key): array {
            // A school lock serializes identifier uniqueness and privileged provisioning.
            DB::table('schools')->where('id', $actor->school_id)->lockForUpdate()->first();
            app(SchoolAccess::class)->requireAdmin($actor);
            $actor->unsetRelation('school');
            abort_unless(app(SchoolAccess::class)->canWrite($actor), 403, 'School is read-only.');
            abort_unless(is_string($key) && preg_match('/\A[A-Za-z0-9_-]{16,160}\z/', $key), 422);
            ksort($data);
            $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $prior = DB::table('idempotency_records')->where('school_id', $actor->school_id)->where('actor_id', $actor->user_id)->where('operation', 'staff.provision')->where('key', $key)->first();
            if ($prior) {
                abort_unless(hash_equals($prior->body_hash, $hash), 409);

                abort_unless(Membership::findOrFail($prior->membership_id)->user->activation_required, 409);

                return ['membership_id' => $prior->membership_id, 'activation_status' => 'pending', 'replayed' => true];
            }
            if (Membership::where('school_id', $actor->school_id)->where('login_identifier', $data['login_identifier'])->exists()) {
                throw ValidationException::withMessages(['login_identifier' => 'Identifier already in use.']);
            }
            $secret = Str::password(32);
            $user = User::create(['name' => $data['name'], 'password' => $secret, 'must_change_password' => true, 'activation_required' => true]);
            $membership = Membership::create(['school_id' => $actor->school_id, 'user_id' => $user->id, 'login_identifier' => $data['login_identifier'], 'role' => $data['role'], 'status' => 'active']);
            AuditEvent::create(['school_id' => $actor->school_id, 'actor_user_id' => $actor->user_id, 'action' => 'staff.provisioned', 'resource_id' => $membership->id, 'request_id' => $requestId]);

            DB::table('idempotency_records')->insert(['id' => (string) Str::ulid(), 'school_id' => $actor->school_id, 'actor_id' => $actor->user_id, 'operation' => 'staff.provision', 'key' => $key, 'body_hash' => $hash, 'membership_id' => $membership->id, 'created_at' => now(), 'updated_at' => now()]);

            return ['membership_id' => $membership->id, 'activation_status' => 'pending', 'replayed' => false, 'local_handover' => app(AccountActivation::class)->issue($actor, $membership->id, $requestId)];
        }, 3);
    }
}
