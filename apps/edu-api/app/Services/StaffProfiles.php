<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\School;
use App\Models\StaffProfile;
use App\Models\User;
use App\Support\Api;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StaffProfiles
{
    private const ROLES = ['principal', 'teacher'];

    private function members(string $school): Builder
    {
        return DB::table('memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.school_id', $school);
    }

    private function profiles(string $school): Builder
    {
        return $this->members($school)->join('staff_profiles as p', function ($join): void {
            $join->on('p.school_id', '=', 'm.school_id')->on('p.membership_id', '=', 'm.id');
        })->select('p.id', 'p.created_at', 'p.updated_at', 'm.id as membership_id', 'm.role', 'm.status',
            'u.name as display_name', 'u.status as user_status', 'u.is_platform_admin', 'u.activation_required', 'u.must_change_password');
    }

    private function eligible(object $row): bool
    {
        return in_array($row->role, self::ROLES, true) && $row->status === 'active' && $row->user_status === 'active'
            && ! $row->is_platform_admin && ! $row->activation_required && ! $row->must_change_password;
    }

    private function project(object $row): array
    {
        return ['id' => $row->id, 'membership_id' => $row->membership_id, 'display_name' => $row->display_name,
            'role' => $row->role, 'eligible_for_registration' => $this->eligible($row),
            'created_at' => CarbonImmutable::parse($row->created_at, 'UTC')->toRfc3339String(),
            'updated_at' => CarbonImmutable::parse($row->updated_at, 'UTC')->toRfc3339String()];
    }

    public function listing(Membership $actor, array $input, bool $candidates): array
    {
        app(SchoolAccess::class)->requireAdmin($actor);
        $id = $candidates ? 'm.id' : 'p.id';
        $query = $candidates ? $this->members($actor->school_id)->whereIn('m.role', self::ROLES)
            ->where('m.status', 'active')->where('u.status', 'active')->where('u.is_platform_admin', false)
            ->where('u.activation_required', false)->where('u.must_change_password', false)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('staff_profiles as p')
                ->whereColumn('p.school_id', 'm.school_id')->whereColumn('p.membership_id', 'm.id'))
            ->select('m.id as membership_id', 'm.role', 'u.name as display_name')
            : $this->profiles($actor->school_id);
        if (isset($input['q'])) {
            $prefix = strtr(mb_strtolower($input['q']), ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $query->whereRaw("LOWER(u.name) LIKE ? ESCAPE '!'", [$prefix]);
        }
        if (isset($input['cursor'])) {
            $query->where($id, '>', strtolower($input['cursor']));
        }
        $rows = $query->orderBy($id)->limit(26)->get();
        $items = $rows->take(25)->values();
        $last = $items->last();

        return ['items' => $candidates ? $items : $items->map($this->project(...)),
            'next_cursor' => $rows->count() > 25 ? ($candidates ? $last->membership_id : $last->id) : null];
    }

    public function detail(Membership $actor, string $id): array
    {
        app(SchoolAccess::class)->requireAdmin($actor);

        return $this->project($this->profiles($actor->school_id)->where('p.id', strtolower($id))->firstOrFail());
    }

    public function register(Membership $actor, string $targetId, string $key, string $requestId): array
    {
        $access = app(SchoolAccess::class);
        $access->requireAdmin($actor);
        $targetId = strtolower($targetId);
        $target = Membership::where('school_id', $actor->school_id)->whereRaw('LOWER(id) = ?', [$targetId])->first();

        return DB::transaction(function () use ($actor, $target, $targetId, $key, $requestId, $access): array {
            $school = School::whereKey($actor->school_id)->lockForUpdate()->firstOrFail();
            // Match the school → users → memberships lock order used by membership changes.
            User::whereIn('id', array_filter([$actor->user_id, $target?->user_id]))->orderBy('id')->lockForUpdate()->get();
            Membership::where('school_id', $school->id)->whereIn('id', array_filter([$actor->id, $target?->id]))
                ->orderBy('id')->lockForUpdate()->get();
            $access->requireAdmin($actor);
            abort_unless($school->data_stage !== 'demo' && $access->schoolCanWrite($school), 403);
            abort_unless($target, 404);
            $scope = ['school_id' => $school->id, 'actor_id' => $actor->user_id, 'operation' => 'staff.profile.register', 'key' => $key];
            $hash = hash('sha256', json_encode(['membership_id' => $targetId], JSON_THROW_ON_ERROR));
            $receipt = DB::table('idempotency_records')->where($scope)->lockForUpdate()->first();
            if ($receipt && ! hash_equals($receipt->body_hash, $hash)) {
                $this->conflict('IDEMPOTENCY_CONFLICT', 'This key was already used for another registration.');
            }
            $current = $this->members($school->id)->where('m.id', $target->id)->select('m.role', 'm.status',
                'u.status as user_status', 'u.is_platform_admin', 'u.activation_required', 'u.must_change_password')->lockForUpdate()->firstOrFail();
            if (! $this->eligible($current)) {
                $this->conflict('STAFF_MEMBERSHIP_INELIGIBLE', 'This membership cannot be registered.');
            }
            $profile = $this->profiles($school->id)->where('p.membership_id', $target->id);
            if ($receipt) {
                return ['resource' => $this->project($profile->firstOrFail()), 'replayed' => true];
            }
            if ((clone $profile)->exists()) {
                $this->conflict('STAFF_PROFILE_EXISTS', 'This membership already has a staff profile.');
            }
            $created = StaffProfile::create(['school_id' => $school->id, 'membership_id' => $target->id]);
            AuditEvent::create(['school_id' => $school->id, 'actor_user_id' => $actor->user_id,
                'action' => 'staff.profile.created', 'resource_id' => $created->id, 'request_id' => $requestId,
                'details' => ['membership_id' => $target->id, 'role' => $current->role]]);
            DB::table('idempotency_records')->insert($scope + ['id' => (string) Str::ulid(), 'body_hash' => $hash,
                'membership_id' => $target->id, 'created_at' => now(), 'updated_at' => now()]);

            return ['resource' => $this->project($profile->firstOrFail()), 'replayed' => false];
        }, 3);
    }

    private function conflict(string $code, string $message): never
    {
        throw new HttpResponseException(Api::error($code, $message, 409));
    }
}
