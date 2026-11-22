<?php

namespace App\Services;

use App\Models\School;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlatformSessionReview
{
    public function queue(Request $request, array $filters): array
    {
        $this->authorize($request);
        $school = isset($filters['school_id']) ? strtolower($filters['school_id']) : null;
        $search = isset($filters['q']) ? mb_strtolower($filters['q']) : null;
        $limit = (int) ($filters['limit'] ?? 25);
        $scope = [$school, $search, $limit];
        $query = DB::table('session_change_requests as r')
            ->join('schools as s', 's.id', '=', 'r.school_id')
            ->join('academic_sessions as a', fn ($join) => $join->on('a.school_id', '=', 'r.school_id')->on('a.id', '=', 'r.session_id'))
            ->where('r.state', 'pending')
            ->when($school, fn ($q) => $q->whereRaw('LOWER(r.school_id) = ?', [$school]));
        if ($search !== null) {
            if (Str::isUlid($search)) {
                $query->whereRaw('LOWER(r.id) = ?', [$search]);
            } else {
                $prefix = strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
                $query->where(function ($q) use ($prefix): void {
                    $q->whereRaw("LOWER(s.code) LIKE ? ESCAPE '!'", [$prefix])
                        ->orWhereRaw("LOWER(s.name) LIKE ? ESCAPE '!'", [$prefix])
                        ->orWhereRaw("LOWER(a.name) LIKE ? ESCAPE '!'", [$prefix]);
                });
            }
        }
        if (isset($filters['cursor'])) {
            $cursor = $this->decode($filters['cursor'], 422);
            abort_unless(($cursor['purpose'] ?? null) === 'session-review-queue'
                && ($cursor['scope'] ?? null) === $scope && isset($cursor['at'], $cursor['id']), 422);
            $query->where(fn ($q) => $q->where('r.created_at', '<', $cursor['at'])
                ->orWhere(fn ($same) => $same->where('r.created_at', $cursor['at'])->where('r.id', '<', $cursor['id'])));
        }
        $rows = $query->orderByDesc('r.created_at')->orderByDesc('r.id')->limit($limit + 1)->get([
            'r.id', 'r.version', 'r.created_at', 'r.school_id', 's.code as school_code', 's.name as school_name',
            'r.session_id', 'a.name as session_name', 'r.proposed_start', 'r.proposed_end',
        ]);
        $more = $rows->count() > $limit;
        $items = $rows->take($limit)->values();
        $last = $items->last();
        $cursor = $more ? Crypt::encryptString(json_encode([
            'purpose' => 'session-review-queue', 'scope' => $scope, 'at' => $last->created_at, 'id' => $last->id,
        ], JSON_THROW_ON_ERROR)) : null;
        $this->audit($request, 'queue_read', ['school_id' => $school, 'result_count' => $items->count()]);

        return ['items' => $items, 'next_cursor' => $cursor];
    }

    public function preview(Request $request, string $id): array
    {
        $this->authorize($request);

        return DB::transaction(function () use ($request, $id): array {
            $schoolId = DB::table('session_change_requests')->where('id', $id)->value('school_id');
            abort_unless($schoolId, 404);
            // Serialize the snapshot with the same school-first lock as decisions and calendar changes.
            $school = School::whereKey($schoolId)->lockForUpdate()->firstOrFail();
            $this->authorize($request);
            $row = DB::table('session_change_requests')->where(['school_id' => $schoolId, 'id' => $id])->lockForUpdate()->firstOrFail();
            $session = DB::table('academic_sessions')->where(['school_id' => $schoolId, 'id' => $row->session_id])->lockForUpdate()->firstOrFail();
            $snapshot = $this->snapshot($school, $row, $session);
            $token = $snapshot['can_approve'] ? Crypt::encryptString(json_encode([
                'purpose' => 'session-review-preview', 'binding' => $this->binding($request, $school, $snapshot),
                'expires' => now()->timestamp + 300,
            ], JSON_THROW_ON_ERROR)) : null;
            $this->audit($request, 'preview_read', ['school_id' => $schoolId, 'change_request_id' => $id,
                'outcome' => $snapshot['can_approve'] ? 'approvable' : 'blocked']);

            return $snapshot + ['school' => ['id' => $schoolId, 'code' => $school->code, 'name' => $school->name], 'preview_token' => $token];
        }, 3);
    }

    public function requirePreview(Request $request, School $school, object $row, object $session, ?string $token): void
    {
        abort_unless($token !== null, 422);
        $proof = $this->decode($token, 409);
        $snapshot = $this->snapshot($school, $row, $session);
        abort_unless(($proof['purpose'] ?? null) === 'session-review-preview'
            && is_int($proof['expires'] ?? null) && $proof['expires'] > now()->timestamp
            && $proof['expires'] <= now()->timestamp + 300 && $snapshot['can_approve']
            && is_string($proof['binding'] ?? null)
            && hash_equals($this->binding($request, $school, $snapshot), $proof['binding']), 409);
    }

    private function snapshot(School $school, object $row, object $session): array
    {
        $changes = app(AcademicSessionChanges::class);
        $zone = $school->settings['timezone'] ?? null;
        $zone = is_string($zone) && in_array($zone, timezone_identifiers_list(), true) ? $zone : null;
        $today = $zone ? CarbonImmutable::now($zone)->toDateString() : null;
        $submitted = json_decode($row->impact, true, flags: JSON_THROW_ON_ERROR);
        $current = $changes->impact($school->id, $session, $row->proposed_start, $row->proposed_end);
        $blockers = array_keys(array_filter([
            'REQUEST_RESOLVED' => $row->state !== 'pending',
            'SESSION_NOT_ACTIVE' => $session->state !== 'active',
            'SESSION_VERSION_CHANGED' => $session->version !== $row->session_version,
            'IMPACT_CHANGED' => $current !== $submitted,
            'OVERLAP' => $changes->hasOverlap($school->id, $row->proposed_start, $row->proposed_end, $session->id),
            'SCHOOL_WRITE_DISABLED' => ! app(SchoolAccess::class)->schoolCanWrite($school),
            'INVALID_SCHOOL_TIMEZONE' => $zone === null,
            'OUTSIDE_LOCAL_RANGE' => $today !== null && ($today < $row->proposed_start || $today > $row->proposed_end),
        ]));

        return [
            'id' => $row->id, 'version' => $row->version, 'state' => $row->state, 'reason' => $row->reason,
            'submitted_session_version' => $row->session_version,
            'submitted' => ['dates' => $submitted['before'], 'class_offerings' => $submitted['class_offerings']],
            'session' => ['id' => $session->id, 'name' => $session->name, 'state' => $session->state,
                'version' => $session->version, 'dates' => $current['before'], 'class_offerings' => $current['class_offerings']],
            'proposed' => $current['proposed'], 'timezone' => $zone, 'local_date' => $today,
            'blockers' => $blockers, 'can_approve' => $blockers === [],
        ];
    }

    private function binding(Request $request, School $school, array $snapshot): string
    {
        return hash('sha256', json_encode([
            $request->user()->id, $request->session()->getId(), $school->id, $school->access_state,
            $school->data_stage, $school->demo_ends_at?->timestamp, $school->entitlement_ends_at?->timestamp, $snapshot,
        ], JSON_THROW_ON_ERROR));
    }

    private function authorize(Request $request): void
    {
        abort_unless(app(PlatformAssurance::class)->allows($request, 'platform.sessions.review'), 403);
    }

    private function decode(string $token, int $failure): array
    {
        try {
            $decoded = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            abort($failure);
        }
        abort_unless(is_array($decoded), $failure);

        return $decoded;
    }

    private function audit(Request $request, string $action, array $details): void
    {
        DB::table('platform_security_audits')->insert([
            'id' => (string) Str::ulid(), 'actor_user_id' => $request->user()->id,
            'action' => 'platform.sessions.'.$action, 'request_id' => $request->attributes->get('request_id'),
            'details' => json_encode(['capability' => 'platform.sessions.review'] + $details, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
