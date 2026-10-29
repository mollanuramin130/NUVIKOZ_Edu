<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MembershipSecurity
{
    // Internal command, not a new public account-provisioning/deletion API.
    public function change(Membership $actor, string $targetId, array $data, string $requestId): array
    {
        app(SchoolAccess::class)->requireAdmin($actor);
        Validator::make($data, ['role' => ['sometimes', 'in:principal,teacher,student,parent'], 'status' => ['sometimes', 'in:active,inactive'], 'version' => ['required', 'integer', 'min:1']])->validate();
        abort_unless(count($data) > 1 && array_diff(array_keys($data), ['role', 'status', 'version']) === [], 422);

        return DB::transaction(function () use ($actor, $targetId, $data, $requestId) {
            School::whereKey($actor->school_id)->lockForUpdate()->firstOrFail();
            $target = Membership::where('school_id', $actor->school_id)->whereKey($targetId)->firstOrFail();
            User::whereIn('id', [$actor->user_id, $target->user_id])->orderBy('id')->lockForUpdate()->get();
            $actor = Membership::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $target = Membership::whereKey($target->id)->lockForUpdate()->firstOrFail();
            app(SchoolAccess::class)->requireAdmin($actor);
            abort_unless(app(SchoolAccess::class)->canWrite($actor) && $actor->user_id !== $target->user_id, 403);
            abort_unless((int) $target->permission_version === (int) $data['version'], 409);
            // Do not invent profile/guardian links through a role conversion shortcut.
            abort_if(isset($data['role']) && in_array($data['role'], ['student', 'parent'], true) && $data['role'] !== $target->role->value, 403);
            $before = ['role' => $target->role->value, 'status' => $target->status];
            $target->fill(array_diff_key($data, ['version' => true]));
            $target->save();
            AuditEvent::create(['school_id' => $actor->school_id, 'actor_user_id' => $actor->user_id, 'action' => 'membership.security_changed', 'resource_id' => $target->id, 'request_id' => $requestId, 'details' => ['before' => $before, 'after' => ['role' => $target->role->value, 'status' => $target->status], 'version' => (int) $target->permission_version]]);

            return ['version' => (int) $target->permission_version];
        }, 3);
    }
}
