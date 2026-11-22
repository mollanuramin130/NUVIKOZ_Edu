<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AcademicSessionChanges
{
    public function requireNoOverlap(string $school, string $start, string $end, ?string $except = null): void
    {
        abort_if($this->hasOverlap($school, $start, $end, $except), 409, 'The proposed dates overlap another session.');
    }

    public function hasOverlap(string $school, string $start, string $end, ?string $except = null): bool
    {
        return DB::table('academic_sessions')->where('school_id', $school)->whereIn('state', ['future', 'active'])
            ->where('starts_on', '<=', $end)->where('ends_on', '>=', $start)
            ->when($except, fn ($query) => $query->where('id', '!=', $except))
            ->lockForUpdate()->first() !== null;
    }

    private function session(string $school, string $id): object
    {
        return DB::table('academic_sessions')->where(['school_id' => $school, 'id' => $id])->lockForUpdate()->firstOrFail();
    }

    private function requireCurrentRange(School $school, string $start, string $end): void
    {
        $zone = $school->settings['timezone'] ?? null;
        abort_unless(is_string($zone) && in_array($zone, timezone_identifiers_list(), true), 403);
        $today = CarbonImmutable::now($zone)->toDateString();
        abort_unless($start <= $today && $today <= $end, 409, 'The change would move this active session outside the current date.');
    }

    public function impact(string $school, object $session, string $start, string $end): array
    {
        return [
            'before' => ['starts_on' => $session->starts_on, 'ends_on' => $session->ends_on],
            'proposed' => ['starts_on' => $start, 'ends_on' => $end],
            'class_offerings' => DB::table('class_offerings')->where(['school_id' => $school, 'session_id' => $session->id])->count(),
        ];
    }

    private function result(object $row, bool $replayed): array
    {
        return ['id' => $row->id, 'session_id' => $row->session_id, 'state' => $row->state, 'version' => $row->version,
            'impact' => json_decode($row->impact, true, flags: JSON_THROW_ON_ERROR), 'replayed' => $replayed];
    }

    private function inputHash(array $data): string
    {
        ksort($data);

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function submit(Membership $actor, string $id, array $data, string $key, string $request): array
    {
        return DB::transaction(function () use ($actor, $id, $data, $key, $request): array {
            $school = School::whereKey($actor->school_id)->lockForUpdate()->firstOrFail();
            User::whereKey($actor->user_id)->lockForUpdate()->firstOrFail();
            $actor = Membership::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $access = app(SchoolAccess::class);
            $access->requireAdmin($actor);
            abort_unless($access->canWrite($actor), 403);
            $session = $this->session($school->id, $id);
            $scope = ['school_id' => $school->id, 'session_id' => $id, 'requester_id' => $actor->user_id, 'submit_key' => $key];
            $previous = DB::table('session_change_requests')->where($scope)->lockForUpdate()->first();
            $hash = $this->inputHash($data);
            if ($previous) {
                abort_unless(hash_equals($previous->submit_hash, $hash), 409);

                return $this->result($previous, true);
            }
            abort_unless($session->state === 'active' && $session->version === $data['version'], 409, 'The session changed or is no longer active.');
            $this->requireCurrentRange($school, $data['proposed_start'], $data['proposed_end']);
            $this->requireNoOverlap($school->id, $data['proposed_start'], $data['proposed_end'], $id);
            $impact = $this->impact($school->id, $session, $data['proposed_start'], $data['proposed_end']);
            $row = $scope + ['id' => (string) Str::ulid(), 'proposed_start' => $data['proposed_start'], 'proposed_end' => $data['proposed_end'],
                'session_version' => $session->version, 'version' => 1, 'state' => 'pending', 'reason' => $data['reason'],
                'impact' => json_encode($impact, JSON_THROW_ON_ERROR), 'submit_hash' => $hash, 'created_at' => now(), 'updated_at' => now()];
            DB::table('session_change_requests')->insert($row);
            AuditEvent::create(['school_id' => $school->id, 'actor_user_id' => $actor->user_id, 'action' => 'academic.session_change.requested',
                'resource_id' => $row['id'], 'request_id' => $request, 'details' => ['reason' => $data['reason'], 'impact' => $impact]]);

            return $this->result((object) $row, false);
        }, 3);
    }

    public function review(Request $request, string $id, array $data, string $key): array
    {
        $assurance = app(PlatformAssurance::class);
        abort_unless($assurance->allows($request, 'platform.sessions.review'), 403);
        $schoolId = DB::table('session_change_requests')->where('id', $id)->value('school_id');
        abort_unless($schoolId, 404);

        return DB::transaction(function () use ($request, $id, $data, $key, $schoolId, $assurance): array {
            $school = School::whereKey($schoolId)->lockForUpdate()->firstOrFail();
            $actor = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($assurance->allows($request, 'platform.sessions.review'), 403);
            $query = DB::table('session_change_requests')->where(['school_id' => $schoolId, 'id' => $id]);
            $row = (clone $query)->lockForUpdate()->firstOrFail();
            $hash = $this->inputHash($data);
            if ($row->state !== 'pending') {
                abort_unless($row->reviewer_id === $actor->id && $row->review_key === $key && hash_equals($row->review_hash, $hash), 409);

                return $this->result($row, true);
            }
            abort_unless($row->version === $data['version'], 409);
            if ($data['decision'] === 'approve') {
                abort_unless(app(SchoolAccess::class)->schoolCanWrite($school), 403);
                $session = $this->session($schoolId, $row->session_id);
                abort_unless($session->state === 'active' && $session->version === $row->session_version, 409, 'The session changed after submission.');
                app(PlatformSessionReview::class)->requirePreview($request, $school, $row, $session, $data['preview_token'] ?? null);
                DB::table('academic_sessions')->where(['school_id' => $schoolId, 'id' => $session->id])->update([
                    'starts_on' => $row->proposed_start, 'ends_on' => $row->proposed_end, 'version' => $session->version + 1, 'updated_at' => now(),
                ]);
            }
            $row->state = $data['decision'] === 'approve' ? 'approved' : 'rejected';
            $row->version++;
            $query->update(['state' => $row->state, 'version' => $row->version, 'reviewer_id' => $actor->id,
                'review_key' => $key, 'review_hash' => $hash, 'updated_at' => now()]);
            AuditEvent::create(['school_id' => $schoolId, 'actor_user_id' => $actor->id, 'action' => 'academic.session_change.'.$row->state,
                'resource_id' => $id, 'request_id' => $request->attributes->get('request_id'),
                'details' => ['reason' => $data['reason'], 'impact' => json_decode($row->impact, true, flags: JSON_THROW_ON_ERROR)]]);

            return $this->result($row, false);
        }, 3);
    }
}
