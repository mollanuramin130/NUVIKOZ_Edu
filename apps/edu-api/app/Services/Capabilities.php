<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class Capabilities
{
    // These are permission ceilings, not implemented academic/financial workflows.
    public const DELEGABLE = [
        'admission.profile.manage' => ['principal'],
        'financial.export' => ['principal'],
        'certificate.issue' => ['principal'],
        'marks.enter' => ['principal', 'teacher'],
    ];

    public function current(Membership $m): Membership
    {
        $m = Membership::whereKey($m->id)->where('school_id', $m->school_id)->where('user_id', $m->user_id)->with(['school', 'user'])->firstOrFail();
        abort_unless($m->status === 'active' && $m->user->status === 'active' && ! $m->user->is_platform_admin && ! $m->user->activation_required && ! $m->user->must_change_password && ! in_array($m->school->access_state, ['suspended', 'archived'], true), 403);

        return $m;
    }

    public function allows(Membership $m, string $capability): bool
    {
        $m = $this->current($m);
        if (! array_key_exists($capability, self::DELEGABLE)) {
            return false;
        }
        if ($m->school->data_stage === 'demo' && in_array($capability, ['financial.export', 'certificate.issue'], true)) {
            return false;
        }
        if ($capability !== 'financial.export' && ! app(SchoolAccess::class)->canWrite($m)) {
            return false;
        }
        if ($m->role->value === 'school_admin') {
            return app(PrivilegedMfa::class)->verified($m, request());
        }
        if (! in_array($m->role->value, self::DELEGABLE[$capability], true)) {
            return false;
        }

        return DB::table('membership_permissions')->where('school_id', $m->school_id)->where('membership_id', $m->id)->where('capability', $capability)->where('allowed', true)->exists();
    }

    public function view(Membership $actor): array
    {
        $actor = $this->current($actor);
        app(SchoolAccess::class)->requireAdmin($actor);

        return ['school_id' => $actor->school_id, 'registry' => self::DELEGABLE, 'memberships' => Membership::where('school_id', $actor->school_id)->orderBy('id')->get()->map(function ($m) {
            return ['id' => $m->id, 'role' => $m->role->value, 'status' => $m->status, 'version' => (int) $m->permission_version, 'assignments' => DB::table('membership_permissions')->where('school_id', $m->school_id)->where('membership_id', $m->id)->get(['capability', 'allowed']), 'effective' => $m->status === 'active' && $m->user->status === 'active' && ! $m->user->activation_required && ! $m->user->must_change_password ? array_values(array_filter(array_keys(self::DELEGABLE), fn ($cap) => $this->allows($m, $cap))) : []];
        })->all()];
    }

    public function assign(Membership $actor, string $targetId, array $data, string $requestId): array
    {
        app(SchoolAccess::class)->requireAdmin($actor);
        Validator::make($data, ['capability' => ['required', 'string', 'in:'.implode(',', array_keys(self::DELEGABLE))], 'allowed' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:1']])->validate();
        abort_unless(array_diff(array_keys($data), ['capability', 'allowed', 'version']) === [], 422);

        return DB::transaction(function () use ($actor, $targetId, $data, $requestId) {
            School::whereKey($actor->school_id)->lockForUpdate()->firstOrFail();
            $target = Membership::where('school_id', $actor->school_id)->whereKey($targetId)->firstOrFail();
            User::whereIn('id', [$actor->user_id, $target->user_id])->orderBy('id')->lockForUpdate()->get();
            $actor = Membership::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $target = Membership::whereKey($target->id)->lockForUpdate()->firstOrFail();
            app(SchoolAccess::class)->requireAdmin($actor);
            abort_unless(app(SchoolAccess::class)->canWrite($actor), 403);
            $target = $this->current($target);
            abort_unless($target->user_id !== $actor->user_id && in_array($target->role->value, self::DELEGABLE[$data['capability']], true), 403);
            abort_unless((int) $target->permission_version === (int) $data['version'], 409);
            $query = DB::table('membership_permissions')->where('school_id', $target->school_id)->where('membership_id', $target->id)->where('capability', $data['capability']);
            $prior = $query->first();
            DB::table('membership_permissions')->updateOrInsert(['school_id' => $target->school_id, 'membership_id' => $target->id, 'capability' => $data['capability']], ['id' => $prior?->id ?? (string) Str::ulid(), 'allowed' => $data['allowed'], 'created_at' => $prior?->created_at ?? now(), 'updated_at' => now()]);
            DB::table('memberships')->where('id', $target->id)->update(['permission_version' => $target->permission_version + 1, 'updated_at' => now()]);
            AuditEvent::create(['school_id' => $actor->school_id, 'actor_user_id' => $actor->user_id, 'action' => $data['allowed'] ? 'permission.granted' : 'permission.revoked', 'resource_id' => $target->id, 'request_id' => $requestId, 'details' => ['capability' => $data['capability'], 'before' => $prior ? (bool) $prior->allowed : null, 'after' => (bool) $data['allowed'], 'from_version' => (int) $data['version'], 'to_version' => (int) $data['version'] + 1]]);

            return ['version' => (int) $data['version'] + 1, 'allowed' => (bool) $data['allowed']];
        }, 3);
    }
}
