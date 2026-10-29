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
    public const KINDS = ['sessions', 'classes', 'class-offerings', 'sections', 'subjects', 'subject-offerings'];

    private function table(string $kind): string
    {
        abort_unless(in_array($kind, self::KINDS, true), 404);

        return ['sessions' => 'academic_sessions', 'classes' => 'classes', 'class-offerings' => 'class_offerings', 'sections' => 'sections', 'subjects' => 'subjects', 'subject-offerings' => 'subject_offerings'][$kind];
    }

    private function rules(string $kind): array
    {
        return match ($kind) {
            'sessions' => ['name' => ['required', 'string', 'max:60'], 'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on']],
            'classes' => ['name' => ['required', 'string', 'max:80'], 'grade_label' => ['sometimes', 'nullable', 'string', 'max:32'], 'sort_order' => ['required', 'integer', 'min:0', 'max:2147483647']],
            'class-offerings' => ['session_id' => ['required', 'ulid'], 'class_id' => ['required', 'ulid']],
            'sections' => ['class_offering_id' => ['required', 'ulid'], 'name' => ['required', 'string', 'max:40'], 'class_teacher_membership_id' => ['nullable', 'prohibited']],
            'subjects' => ['code' => ['required', 'string', 'max:32'], 'name' => ['required', 'string', 'max:100']],
            'subject-offerings' => ['class_offering_id' => ['required', 'ulid'], 'subject_id' => ['required', 'ulid'], 'compulsory' => ['required', 'boolean', function ($attribute, $value, $fail) {
                if (! is_bool($value)) {
                    $fail('Use a JSON boolean.');
                }
            }]],
        };
    }

    private function filters(string $kind): array
    {
        return match ($kind) {
            'class-offerings' => ['session_id' => 'academic_sessions', 'class_id' => 'classes'],
            'sections' => ['class_offering_id' => 'class_offerings'],
            'subject-offerings' => ['class_offering_id' => 'class_offerings', 'subject_id' => 'subjects'],
            default => [],
        };
    }

    private function owned(string $table, Membership $actor, string $id): object
    {
        $row = DB::table($table)->where('school_id', $actor->school_id)->where('id', $id)->first();
        abort_unless($row, 404);

        return $row;
    }

    private function futureSession(Membership $actor, string $id): void
    {
        $session = $this->owned('academic_sessions', $actor, $id);
        $zone = $actor->school->settings['timezone'] ?? null;
        abort_unless(is_string($zone) && in_array($zone, timezone_identifiers_list(), true), 403);
        abort_unless($session->state === 'future' && $session->starts_on > CarbonImmutable::now($zone)->toDateString(), 409);
    }

    private function relations(Membership $actor, string $kind, array $data): void
    {
        if ($kind === 'class-offerings') {
            $this->futureSession($actor, $data['session_id']);
            abort_if($this->owned('classes', $actor, $data['class_id'])->archived_at !== null, 409);
        }
        if (in_array($kind, ['sections', 'subject-offerings'], true)) {
            $offering = $this->owned('class_offerings', $actor, $data['class_offering_id']);
            $this->futureSession($actor, $offering->session_id);
            abort_if($this->owned('classes', $actor, $offering->class_id)->archived_at !== null, 409);
        }
        if ($kind === 'subject-offerings') {
            abort_if($this->owned('subjects', $actor, $data['subject_id'])->archived_at !== null, 409);
        }
    }

    private function duplicateFields(string $kind): array
    {
        return match ($kind) {
            'class-offerings' => ['session_id', 'class_id'],
            'sections' => ['class_offering_id', 'name'],
            'subjects' => ['code'],
            'subject-offerings' => ['class_offering_id', 'subject_id'],
            default => ['name'],
        };
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
        $filters = $this->filters($kind);
        $rules = ['cursor' => ['sometimes', 'ulid']];
        foreach ($filters as $field => $table) {
            $rules[$field] = ['sometimes', 'ulid'];
        }
        Validator::make($query, $rules)->validate();
        abort_unless(array_diff(array_keys($query), array_keys($rules)) === [], 422);
        foreach ($filters as $field => $table) {
            if (isset($query[$field])) {
                $this->owned($table, $actor, $query[$field]);
            }
        }
        $rows = DB::table($this->table($kind))->where('school_id', $actor->school_id)
            ->where(array_intersect_key($query, $filters))
            ->when(isset($query['cursor']), fn ($q) => $q->where('id', '>', $query['cursor']))
            ->orderBy('id')->limit(26)->get();
        $more = $rows->count() > 25;
        $rows = $rows->take(25);

        return ['items' => $rows->map(fn ($r) => $this->projection($r, $kind))->all(), 'next_cursor' => $more ? $rows->last()->id : null];
    }

    private function projection(object $row, string $kind): array
    {
        $fields = match ($kind) {
            'sessions' => ['id', 'name', 'starts_on', 'ends_on', 'state', 'version'],
            'classes' => ['id', 'name', 'grade_label', 'sort_order', 'version'],
            'class-offerings' => ['id', 'session_id', 'class_id', 'version'],
            'sections' => ['id', 'class_offering_id', 'name', 'class_teacher_membership_id', 'version'],
            'subjects' => ['id', 'code', 'name', 'version'],
            'subject-offerings' => ['id', 'class_offering_id', 'subject_id', 'compulsory', 'version'],
        };
        if ($kind === 'subject-offerings') {
            $row->compulsory = (bool) $row->compulsory;
        }

        return array_intersect_key((array) $row, array_flip($fields));
    }

    public function create(Membership $actor, string $kind, array $data, ?string $key, string $requestId): array
    {
        $table = $this->table($kind);
        $this->current($actor);
        $rules = $this->rules($kind);
        foreach (['name', 'grade_label', 'code'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]);
            }
        }
        if ($kind === 'sections' && array_key_exists('class_teacher_membership_id', $data)) {
            abort_unless($data['class_teacher_membership_id'] === null, 422);
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
                $this->relations($actor, $kind, $data);
                $row = DB::table($table)->where('school_id', $actor->school_id)->where('id', $receipt->resource_id)->firstOrFail();

                return ['resource' => $this->projection($row, $kind), 'replayed' => true];
            }
            $this->relations($actor, $kind, $data);
            $unique = array_intersect_key($data, array_flip($this->duplicateFields($kind)));
            abort_if(DB::table($table)->where('school_id', $actor->school_id)->where($unique)->lockForUpdate()->first(), 409);
            if ($actor->school->data_stage === 'demo') {
                $limit = config('academic.demo_limits.'.$kind);
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
