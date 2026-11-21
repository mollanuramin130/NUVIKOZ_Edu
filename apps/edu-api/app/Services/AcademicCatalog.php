<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Support\Api;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

    private function current(Membership $actor, bool $device = false, bool $write = false): Membership
    {
        $current = Membership::whereKey($actor->id)->where('school_id', $actor->school_id)->where('user_id', $actor->user_id)->with(['school', 'user'])->firstOrFail();
        if (! $device) {
            app(SchoolAccess::class)->requireAdmin($current);
        } else {
            app(SchoolAccess::class)->requireAdminIdentity($current);
            if ($write) {
                abort_unless(app(PrivilegedMfa::class)->deviceVerified($current, request()), 403, 'MFA required.');
            }
        }

        return $current;
    }

    public function listing(Membership $actor, string $kind, array $query, bool $device = false): array
    {
        $actor = $this->current($actor, $device);
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

        $calendar = [];
        if (! $device && $kind === 'sessions') {
            $zone = $actor->school->settings['timezone'] ?? null;
            $valid = is_string($zone) && in_array($zone, timezone_identifiers_list(), true);
            $calendar['calendar'] = ['timezone' => $valid ? $zone : null, 'today' => $valid ? CarbonImmutable::now($zone)->toDateString() : null,
                'can_write' => app(SchoolAccess::class)->canWrite($actor),
                'has_active_session' => DB::table('academic_sessions')->where(['school_id' => $actor->school_id, 'state' => 'active'])->exists()];
        }

        return $calendar + ['items' => $rows->map(fn ($r) => $this->projection($r, $kind))->all(), 'next_cursor' => $more ? $rows->last()->id : null];
    }

    public function transitionSession(Membership $actor, string $id, array $data, ?string $key, string $requestId): array
    {
        abort_unless(in_array($data['action'] ?? null, ['activate', 'close'], true)
            && is_int($data['version'] ?? null) && $data['version'] > 0
            && count($data) === 2 && is_string($key) && preg_match('/\A[A-Za-z0-9_-]{16,160}\z/', $key), 422);
        $target = $data['action'] === 'activate' ? 'active' : 'closed';
        $source = $target === 'active' ? 'future' : 'active';
        $hash = hash('sha256', json_encode([$id, $data['action'], $data['version']], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $id, $data, $key, $requestId, $target, $source, $hash): array {
            $school = School::whereKey($actor->school_id)->lockForUpdate()->firstOrFail();
            User::whereKey($actor->user_id)->lockForUpdate()->firstOrFail();
            Membership::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $actor = $this->current($actor, false, true);
            abort_unless(app(SchoolAccess::class)->canWrite($actor), 403, 'School write access is required.');
            $zone = $school->settings['timezone'] ?? null;
            abort_unless(is_string($zone) && in_array($zone, timezone_identifiers_list(), true), 403, 'Configure a valid school timezone.');
            $query = DB::table('academic_sessions')->where(['school_id' => $school->id, 'id' => $id]);
            $session = (clone $query)->lockForUpdate()->firstOrFail();
            $scope = ['school_id' => $school->id, 'membership_id' => $actor->id, 'operation' => 'sessions.transition', 'key' => $key];
            $receipt = DB::table('academic_command_receipts')->where($scope)->lockForUpdate()->first();
            $result = ['id' => $id, 'state' => $target, 'version' => $data['version'] + 1];
            if ($receipt) {
                abort_unless(hash_equals($receipt->body_hash, $hash), 409, 'This key was used for a different transition.');

                return $result + ['replayed' => true];
            }
            abort_unless($session->state === $source && $session->version === $data['version'], 409, 'Session changed. Refresh before continuing.');
            $today = CarbonImmutable::now($zone)->toDateString();
            if ($target === 'active') {
                abort_if($today < $session->starts_on, 409, 'Activation starts on the school-local start date.');
                abort_if($today > $session->ends_on, 409, 'The activation window has passed.');
                abort_if(DB::table('academic_sessions')->where(['school_id' => $school->id, 'state' => 'active'])->lockForUpdate()->first(), 409, 'Close the existing active session first.');
                app(AcademicSessionChanges::class)->requireNoOverlap($school->id, $session->starts_on, $session->ends_on, $id);
            } else {
                abort_unless($today > $session->ends_on, 409, 'Closure is available after the school-local end date.');
            }

            $query->update(['state' => $target, 'version' => $result['version'], 'updated_at' => now()]);
            AuditEvent::create(['school_id' => $school->id, 'actor_user_id' => $actor->user_id, 'action' => 'academic.sessions.transitioned',
                'resource_id' => $id, 'request_id' => $requestId, 'details' => ['from' => $source, 'to' => $target,
                    'version' => $result['version'], 'school_date' => $today, 'timezone' => $zone]]);
            DB::table('academic_command_receipts')->insert($scope + ['id' => (string) Str::ulid(), 'body_hash' => $hash,
                'resource_id' => $id, 'created_at' => now(), 'updated_at' => now()]);

            return $result + ['replayed' => false];
        }, 3);
    }

    public function editSession(Membership $actor, string $id, array $data, ?string $key, string $requestId): array
    {
        $rules = $this->rules('sessions');
        abort_unless(is_int($data['version'] ?? null) && $data['version'] > 0
            && $data['version'] <= 4294967295
            && array_diff_key($data, $rules + ['version' => true]) === []
            && is_string($key) && preg_match('/\A[A-Za-z0-9_-]{16,160}\z/', $key), 422);
        if (is_string($data['name'] ?? null)) {
            $data['name'] = trim($data['name']);
        }
        $values = Validator::make($data, $rules)->validate();
        $version = $data['version'];
        $hash = hash('sha256', json_encode([$id, $values, $version], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $id, $key, $requestId, $values, $version, $hash): array {
            $school = School::whereKey($actor->school_id)->lockForUpdate()->firstOrFail();
            User::whereKey($actor->user_id)->lockForUpdate()->firstOrFail();
            Membership::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $actor = $this->current($actor, false, true);
            abort_unless(app(SchoolAccess::class)->canWrite($actor), 403);
            $zone = $school->settings['timezone'] ?? null;
            abort_unless(is_string($zone) && in_array($zone, timezone_identifiers_list(), true), 403);

            $query = DB::table('academic_sessions')->where(['school_id' => $school->id, 'id' => $id]);
            $session = (clone $query)->lockForUpdate()->firstOrFail();
            $scope = ['school_id' => $school->id, 'membership_id' => $actor->id, 'operation' => 'sessions.edit', 'key' => $key];
            $receipt = DB::table('academic_command_receipts')->where($scope)->lockForUpdate()->first();
            $result = $values + ['id' => $id, 'state' => 'future', 'version' => $version + 1];
            if ($receipt) {
                if (! hash_equals($receipt->body_hash, $hash)) {
                    $this->editConflict('SESSION_EDIT_KEY_CONFLICT', 'This key was used for a different edit.');
                }

                return $result + ['replayed' => true];
            }

            if ($session->state !== 'future') {
                $this->editConflict('SESSION_NOT_FUTURE', 'Only future sessions can be edited.');
            }
            if ($session->version !== $version || $version === 4294967295) {
                $this->editConflict('SESSION_EDIT_STALE', 'The session version cannot be updated. Refresh sessions.');
            }
            $today = CarbonImmutable::now($zone)->toDateString();
            if ($session->starts_on <= $today || $values['starts_on'] <= $today) {
                $this->editConflict('SESSION_EDIT_WINDOW', 'Both start dates must be after today in the school timezone.');
            }
            $before = ['name' => $session->name, 'starts_on' => $session->starts_on, 'ends_on' => $session->ends_on];
            if ($before === $values) {
                throw ValidationException::withMessages(['session' => 'Change at least one field before saving.']);
            }
            if (DB::table('academic_sessions')->where('school_id', $school->id)->where('name', $values['name'])->where('id', '!=', $id)->lockForUpdate()->first()) {
                $this->editConflict('SESSION_EDIT_DUPLICATE_NAME', 'A session with this name already exists in the school.');
            }
            if (app(AcademicSessionChanges::class)->hasOverlap($school->id, $values['starts_on'], $values['ends_on'], $id)) {
                $this->editConflict('SESSION_EDIT_OVERLAP', 'These dates overlap another future or active session.');
            }

            $query->update($values + ['version' => $result['version']]);
            AuditEvent::create(['school_id' => $school->id, 'actor_user_id' => $actor->user_id, 'action' => 'academic.sessions.edited',
                'resource_id' => $id, 'request_id' => $requestId, 'details' => ['before' => $before, 'after' => $values,
                    'version' => $result['version'], 'school_date' => $today, 'timezone' => $zone]]);
            DB::table('academic_command_receipts')->insert($scope + ['id' => (string) Str::ulid(), 'body_hash' => $hash,
                'resource_id' => $id, 'created_at' => now(), 'updated_at' => now()]);

            return $result + ['replayed' => false];
        }, 3);
    }

    private function editConflict(string $code, string $message): never
    {
        throw new HttpResponseException(Api::error($code, $message, 409));
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

    public function create(Membership $actor, string $kind, array $data, ?string $key, string $requestId, bool $device = false): array
    {
        $table = $this->table($kind);
        $this->current($actor, $device, true);
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

        return DB::transaction(function () use ($actor, $kind, $table, $data, $key, $hash, $requestId, $device) {
            // Same order as identity/security mutation commands: school -> identity -> membership.
            School::whereKey($actor->school_id)->lockForUpdate()->firstOrFail();
            User::whereKey($actor->user_id)->lockForUpdate()->firstOrFail();
            Membership::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $actor = $this->current($actor, $device, true);
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
                app(AcademicSessionChanges::class)->requireNoOverlap($actor->school_id, $data['starts_on'], $data['ends_on']);
            }
            $id = (string) Str::ulid();
            DB::table($table)->insert($data + ['id' => $id, 'school_id' => $actor->school_id, 'created_at' => now(), 'updated_at' => now()]);
            AuditEvent::create(['school_id' => $actor->school_id, 'actor_user_id' => $actor->user_id, 'action' => 'academic.'.$kind.'.created', 'resource_id' => $id, 'request_id' => $requestId, 'details' => ['after' => $data, 'version' => 1]]);
            DB::table('academic_command_receipts')->insert($scope + ['id' => (string) Str::ulid(), 'body_hash' => $hash, 'resource_id' => $id, 'created_at' => now(), 'updated_at' => now()]);

            return ['resource' => $this->projection(DB::table($table)->where('id', $id)->firstOrFail(), $kind), 'replayed' => false];
        }, 3);
    }
}
