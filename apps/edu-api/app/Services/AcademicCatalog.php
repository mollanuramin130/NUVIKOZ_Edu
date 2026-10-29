<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AcademicCatalog
{
    private function table(string $kind): string
    {
        abort_unless(in_array($kind, ['sessions', 'classes'], true), 404);

        return $kind === 'sessions' ? 'academic_sessions' : 'classes';
    }

    private function current(Membership $actor): Membership
    {
        $current = Membership::whereKey($actor->id)->where('school_id', $actor->school_id)->where('user_id', $actor->user_id)->with(['school', 'user'])->firstOrFail();
        app(SchoolAccess::class)->requireAdmin($current);

        return $current;
    }

    public function listing(Membership $actor, string $kind, array $query): array
    {
        $actor = $this->current($actor);
        Validator::make($query, ['cursor' => ['sometimes', 'ulid']])->validate();
        abort_unless(array_diff(array_keys($query), ['cursor']) === [], 422);
        $rows = DB::table($this->table($kind))->where('school_id', $actor->school_id)
            ->when(isset($query['cursor']), fn ($q) => $q->where('id', '>', $query['cursor']))
            ->orderBy('id')->limit(26)->get();
        $more = $rows->count() > 25;
        $rows = $rows->take(25);

        return ['items' => $rows->map(fn ($r) => $this->projection($r, $kind))->all(), 'next_cursor' => $more ? $rows->last()->id : null];
    }

    private function projection(object $row, string $kind): array
    {
        $fields = $kind === 'sessions' ? ['id', 'name', 'starts_on', 'ends_on', 'state', 'version'] : ['id', 'name', 'grade_label', 'sort_order', 'version'];

        return array_intersect_key((array) $row, array_flip($fields));
    }

    public function create(Membership $actor, string $kind, array $data, ?string $key, string $requestId): array
    {
        $table = $this->table($kind);
        $this->current($actor);
        $rules = $kind === 'sessions'
            ? ['name' => ['required', 'string', 'max:60'], 'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on']]
            : ['name' => ['required', 'string', 'max:80'], 'grade_label' => ['sometimes', 'nullable', 'string', 'max:32'], 'sort_order' => ['required', 'integer', 'min:0', 'max:2147483647']];
        foreach (['name', 'grade_label'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]);
            }
        }
        Validator::make($data, $rules)->validate();
        abort_unless(array_diff(array_keys($data), array_keys($rules)) === [], 422);
        abort_unless(is_string($key) && preg_match('/\A[A-Za-z0-9_-]{16,160}\z/', $key), 422);
        ksort($data);
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $kind, $table, $data, $key, $hash, $requestId) {
            // Same order as identity/security mutation commands: school -> identity -> membership.
            School::whereKey($actor->school_id)->lockForUpdate()->firstOrFail();
            User::whereKey($actor->user_id)->lockForUpdate()->firstOrFail();
            Membership::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $actor = $this->current($actor);
            abort_unless(app(SchoolAccess::class)->canWrite($actor), 403);
            $scope = ['school_id' => $actor->school_id, 'membership_id' => $actor->id, 'operation' => $kind.'.create', 'key' => $key];
            // Locking reads avoid stale REPEATABLE READ snapshots after a school-lock wait.
            $receipt = DB::table('academic_command_receipts')->where($scope)->lockForUpdate()->first();
            if ($receipt) {
                abort_unless(hash_equals($receipt->body_hash, $hash), 409);
                $row = DB::table($table)->where('school_id', $actor->school_id)->where('id', $receipt->resource_id)->firstOrFail();

                return ['resource' => $this->projection($row, $kind), 'replayed' => true];
            }
            abort_if(DB::table($table)->where('school_id', $actor->school_id)->where('name', $data['name'])->lockForUpdate()->first(), 409);
            if ($actor->school->data_stage === 'demo') {
                $limit = config('academic.'.($kind === 'sessions' ? 'demo_session_limit' : 'demo_class_limit'));
                abort_unless(is_int($limit) && $limit > 0, 403);
                abort_if(DB::table($table)->where('school_id', $actor->school_id)->lockForUpdate()->get(['id'])->count() >= $limit, 409);
            }
            if ($kind === 'sessions') {
                $zone = $actor->school->settings['timezone'] ?? null;
                abort_unless(is_string($zone) && in_array($zone, timezone_identifiers_list(), true), 403);
                abort_unless($data['starts_on'] > CarbonImmutable::now($zone)->toDateString(), 422);
                abort_if(DB::table($table)->where('school_id', $actor->school_id)->whereIn('state', ['future', 'active'])
                    ->where('starts_on', '<=', $data['ends_on'])->where('ends_on', '>=', $data['starts_on'])->lockForUpdate()->first(), 409);
            }
            $id = (string) Str::ulid();
            DB::table($table)->insert($data + ['id' => $id, 'school_id' => $actor->school_id, 'created_at' => now(), 'updated_at' => now()]);
            AuditEvent::create(['school_id' => $actor->school_id, 'actor_user_id' => $actor->user_id, 'action' => 'academic.'.$kind.'.created', 'resource_id' => $id, 'request_id' => $requestId, 'details' => ['after' => $data, 'version' => 1]]);
            DB::table('academic_command_receipts')->insert($scope + ['id' => (string) Str::ulid(), 'body_hash' => $hash, 'resource_id' => $id, 'created_at' => now(), 'updated_at' => now()]);

            return ['resource' => $this->projection(DB::table($table)->where('id', $id)->firstOrFail(), $kind), 'replayed' => false];
        }, 3);
    }
}
