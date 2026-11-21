<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\School;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SchoolEntitlements
{
    public const FREE = ['version' => 'free-live-v1', 'max_pdf_bytes' => 5_000_000, 'max_active_pdfs' => 50, 'max_daily_pdfs' => 10, 'max_total_bytes' => 500_000_000];

    public const ACTIONS = ['grant_demo', 'grant_free_live', 'enable', 'suspend'];

    private function events(School $school)
    {
        return DB::table('school_entitlement_events')->where('school_id', $school->id);
    }

    private function grant(School $school, bool $lock = false): ?object
    {
        $query = $this->events($school)->whereNotNull('policy')->latest('sequence');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private function current(?object $grant): bool
    {
        return $grant && Carbon::parse($grant->ends_at)->isFuture();
    }

    public function summary(School $school): array
    {
        $grant = $this->grant($school);

        return [
            'access' => $school->access_state,
            'state' => ! $grant ? 'unassigned' : ($this->current($grant) ? 'current' : 'expired'),
            'policy' => $grant?->policy,
            'starts_at' => $grant?->starts_at,
            'ends_at' => $grant?->ends_at,
            'quota' => $grant?->policy === self::FREE['version'] ? self::FREE + $this->quotaSummary($school) : null,
            'history' => $this->events($school)->latest('sequence')->limit(25)->get(['action', 'actor_id', 'sequence', 'starts_at', 'ends_at', 'policy']),
        ];
    }

    public function conversionPreview(School $school, string $actor): array
    {
        $grant = $this->grant($school);
        $eligible = $school->data_stage === 'demo' && $grant?->policy === 'demo-v1'
            && in_array($school->access_state, ['provisional', 'suspended'], true);
        $expires = now()->addMinutes(10)->timestamp;
        $review = $eligible ? $this->conversionRecords($school) : ['digest' => '', 'counts' => [], 'conflicts' => []];
        $eligible = $eligible && $review['conflicts'] === [];
        $snapshot = [$this->conversionSnapshot($school, $grant), $review['digest']];

        return [
            'eligible' => $eligible, 'demo_state' => $grant?->policy !== 'demo-v1' ? 'unassigned' : ($this->current($grant) ? 'current' : 'expired'),
            'record_counts' => $review['counts'], 'conflicts' => $review['conflicts'],
            'access_after' => 'suspended', 'policy_after' => self::FREE['version'],
            'preview' => $eligible ? Crypt::encryptString(json_encode([$actor, $snapshot, $expires], JSON_THROW_ON_ERROR)) : null,
        ];
    }

    private function conversionRecords(School $school, bool $lock = false): array
    {
        $relationships = [
            'academic_sessions' => [], 'classes' => [], 'subjects' => [],
            'class_offerings' => ['session_id' => 'academic_sessions', 'class_id' => 'classes'],
            'sections' => ['class_offering_id' => 'class_offerings'],
            'subject_offerings' => ['class_offering_id' => 'class_offerings', 'subject_id' => 'subjects'],
        ];
        $hash = hash_init('sha256');
        $ids = [];
        $counts = [];
        $conflicts = [];
        foreach ($relationships as $table => $references) {
            $query = DB::table($table)->where('school_id', $school->id)->orderBy('id');
            $rows = ($lock ? $query->lockForUpdate() : $query)->get();
            $counts[$table] = $rows->count();
            $ids[$table] = [];
            hash_update($hash, $table);
            foreach ($rows as $row) {
                $ids[$table][$row->id] = true;
                hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR));
                foreach ($references as $field => $parent) {
                    if (! isset($ids[$parent][$row->$field])) {
                        $conflicts[] = ['table' => $table, 'id' => $row->id, 'field' => $field, 'code' => 'missing_school_reference'];
                    }
                }
                if ($table === 'academic_sessions' && $row->starts_on > $row->ends_on) {
                    $conflicts[] = ['table' => $table, 'id' => $row->id, 'field' => 'ends_on', 'code' => 'invalid_session_dates'];
                }
            }
        }

        return ['digest' => hash_final($hash), 'counts' => $counts, 'conflicts' => $conflicts];
    }

    private function conversionSnapshot(School $school, ?object $grant): array
    {
        return [$school->id, $school->data_stage, $school->access_state, (int) $school->version, $grant?->id, $this->current($grant)];
    }

    private function verifyConversion(School $school, ?object $grant, string $actor, string $preview): void
    {
        try {
            $decoded = json_decode(Crypt::decryptString($preview), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            abort(409, 'Refresh the conversion preview.');
        }

        $review = $this->conversionRecords($school, true);
        abort_unless($review['conflicts'] === [] && $school->data_stage === 'demo' && $grant?->policy === 'demo-v1'
            && in_array($school->access_state, ['provisional', 'suspended'], true)
            && ($decoded[0] ?? null) === $actor && ($decoded[1] ?? null) === [$this->conversionSnapshot($school, $grant), $review['digest']]
            && ($decoded[2] ?? 0) > now()->timestamp, 409, 'Refresh the conversion preview.');
    }

    // Caller holds the school lock and revalidates Platform MFA.
    public function change(School $school, object $link, string $actor, array $command, string $request): array
    {
        $previous = $this->events($school)->where('command_id', $command['command_id'])->lockForUpdate()->first();
        $action = $command['action'];
        $days = $command['term_days'] ?? null;
        $previewHash = $action === 'convert_demo' ? hash('sha256', $command['preview']) : null;
        if ($previous) {
            abort_unless($previous->actor_id === $actor && $previous->action === $action && $previous->term_days === $days, 409);
            if ($previewHash !== null) {
                $receipt = AuditEvent::where('school_id', $school->id)->where('actor_user_id', $actor)
                    ->where('action', 'school.entitlement.convert_demo')->where('resource_id', $previous->id)->lockForUpdate()->first();
                $storedHash = $receipt?->details['preview_hash'] ?? null;
                abort_unless(is_string($storedHash) && hash_equals($storedHash, $previewHash), 409);
            }

            return ['entitlement' => $this->summary($school), 'replayed' => true];
        }
        $member = Membership::where('school_id', $school->id)->whereKey($link->membership_id)->with('user')->first();
        abort_unless($member && $member->role === Role::SchoolAdmin && $member->status === 'active' && $member->user->status === 'active'
            && ! $member->user->activation_required && ! $member->user->must_change_password && ! $member->user->is_platform_admin && $school->access_state !== 'archived', 409);

        $grant = $this->grant($school, true);
        $policy = null;
        $end = null;
        $before = $school->access_state;
        $now = now();
        switch ($action) {
            case 'grant_demo':
                abort_unless(! $grant && $before === 'suspended', 409);
                $policy = 'demo-v1';
                $end = $now->copy()->addDays(30);
                $school->demo_ends_at = $end;
                break;
            case 'convert_demo':
                $this->verifyConversion($school, $grant, $actor, $command['preview']);
                $policy = self::FREE['version'];
                $end = $now->copy()->addDays($days);
                $school->data_stage = 'live';
                $school->access_state = 'suspended';
                $school->entitlement_ends_at = $end;
                break;
            case 'grant_free_live':
                abort_unless((! $grant && $before === 'suspended') || ($grant?->policy === self::FREE['version'] && ! $this->current($grant)), 409);
                $policy = self::FREE['version'];
                $end = $now->copy()->addDays($days);
                $school->entitlement_ends_at = $end;
                break;
            case 'enable':
                abort_unless($before === 'suspended' && $this->current($grant), 409);
                $school->data_stage = $grant->policy === 'demo-v1' ? 'demo' : 'live';
                $school->access_state = $grant->policy === 'demo-v1' ? 'provisional' : 'live_enabled';
                break;
            case 'suspend':
                abort_unless(in_array($before, ['provisional', 'live_enabled'], true), 409);
                $school->access_state = 'suspended';
                break;
        }
        $id = (string) Str::ulid();
        $latest = $this->events($school)->latest('sequence')->lockForUpdate()->first();
        if ($policy && ! $this->localDay($school)) {
            $school->settings = array_replace($school->settings ?? [], ['timezone' => 'Asia/Kolkata']);
        }
        $school->version++;
        $school->save();
        $this->events($school)->insert([
            'id' => $id, 'school_id' => $school->id, 'actor_id' => $actor,
            'command_id' => $command['command_id'], 'sequence' => ($latest->sequence ?? 0) + 1,
            'action' => $action, 'policy' => $policy, 'term_days' => $days, 'starts_at' => $now, 'ends_at' => $end,
        ]);
        AuditEvent::create(['school_id' => $school->id, 'actor_user_id' => $actor, 'action' => 'school.entitlement.'.$action,
            'resource_id' => $id, 'request_id' => $request, 'details' => ['previous_access' => $before, 'access' => $school->access_state, 'policy' => $policy] + ($previewHash === null ? [] : ['preview_hash' => $previewHash])]);

        return ['entitlement' => $this->summary($school), 'replayed' => false];
    }

    private function localDay(School $school): ?string
    {
        $timezone = $school->settings['timezone'] ?? null;

        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true) ? now($timezone)->toDateString() : null;
    }

    private function usage(School $school, bool $lock = false): array
    {
        $day = $this->localDay($school);
        $rows = DB::table('school_pdf_admissions')->where('school_id', $school->id);
        if ($lock) {
            $rows->lockForUpdate();
        }
        $used = ['bytes' => 0, 'active' => 0, 'daily' => 0];
        foreach ($rows->cursor() as $row) {
            $used['bytes'] += $row->bytes;
            $used['active'] += $row->active_delta;
            $used['daily'] += $row->school_date === $day ? 1 : 0;
        }

        return $used + ['school_date' => $day, 'warning' => $used['bytes'] >= 400_000_000, 'storage_blocked' => $used['bytes'] >= self::FREE['max_total_bytes']];
    }

    private function quotaSummary(School $school): array
    {
        $usage = $this->usage($school);
        $pending = $this->pending($school)->get(['bytes', 'active_delta', 'school_date']);
        $usage['reserved_bytes'] = $pending->sum('bytes');
        $usage['reserved_active'] = $pending->sum('active_delta');
        $usage['reserved_daily'] = $pending->where('school_date', $usage['school_date'])->count();
        $projected = $usage['bytes'] + $usage['reserved_bytes'];
        $usage['warning'] = $projected >= 400_000_000;
        $usage['storage_blocked'] = $projected >= self::FREE['max_total_bytes'];

        return $usage;
    }

    private function requireFreeLive(School $school, string $actor): void
    {
        $schoolId = $school->id;
        abort_unless($school->data_stage === 'live' && $school->access_state === 'live_enabled', 403);
        $member = Membership::where('school_id', $schoolId)->where('user_id', $actor)->where('status', 'active')->with('user')->first();
        abort_unless($member && $member->user->status === 'active' && ! $member->user->is_platform_admin
            && ! $member->user->activation_required && ! $member->user->must_change_password, 403);
        $grant = $this->grant($school, true);
        abort_unless($grant?->policy === self::FREE['version'] && $this->current($grant) && $school->entitlement_ends_at?->isFuture(), 403);
    }

    private function pending(School $school)
    {
        return DB::table('school_pdf_reservations')->where('school_id', $school->id)
            ->where('state', 'pending')->where('expires_at', '>', now());
    }

    private function requireCapacity(School $school, int $bytes, int $delta): array
    {
        $usage = $this->usage($school, true);
        abort_unless($usage['school_date'], 409, 'School timezone is required.');
        foreach ($this->pending($school)->lockForUpdate()->get() as $reservation) {
            abort_if($reservation->timezone !== $school->settings['timezone'], 409, 'School timezone changed.');
            $usage['bytes'] += $reservation->bytes;
            $usage['active'] += $reservation->active_delta;
            $usage['daily'] += $reservation->school_date === $usage['school_date'] ? 1 : 0;
        }
        abort_if($bytes > self::FREE['max_pdf_bytes'], 422, 'PDF size limit exceeded.');
        abort_if(self::FREE['max_active_pdfs'] < $usage['active'] + $delta, 409, 'Active PDF limit exceeded.');
        abort_if($usage['daily'] >= self::FREE['max_daily_pdfs'], 409, 'Daily PDF limit exceeded.');
        abort_if(self::FREE['max_total_bytes'] < $usage['bytes'] + $bytes, 409, 'School storage limit exceeded.');

        return $usage;
    }

    /** Internal only: the caller owns document authorization and has verified its school binding. */
    public function reservePdf(string $schoolId, string $documentId, int $bytes, string $commandId, string $actor, string $request): array
    {
        abort_unless(Str::isUuid($commandId) && Str::isUlid($documentId) && $bytes > 0, 422);

        return DB::transaction(function () use ($schoolId, $documentId, $bytes, $commandId, $actor, $request): array {
            $school = School::whereKey($schoolId)->lockForUpdate()->firstOrFail();
            $this->requireFreeLive($school, $actor);
            $key = ['school_id' => $schoolId, 'command_id' => $commandId];
            $prior = DB::table('school_pdf_reservations')->where($key)->lockForUpdate()->first();
            if ($prior) {
                abort_unless($prior->actor_id === $actor && $prior->document_id === $documentId && $prior->bytes === $bytes, 409);

                return ['state' => $prior->state === 'pending' && ! Carbon::parse($prior->expires_at)->isFuture() ? 'expired' : $prior->state, 'replayed' => true];
            }
            abort_if($this->pending($school)->where('document_id', $documentId)->lockForUpdate()->first(), 409, 'Document already reserved.');
            $committed = DB::table('school_pdf_admissions')->where('school_id', $schoolId);
            abort_if((clone $committed)->where('command_id', $commandId)->lockForUpdate()->first(), 409);
            $delta = (clone $committed)->where('document_id', $documentId)->lockForUpdate()->first() ? 0 : 1;
            $usage = $this->requireCapacity($school, $bytes, $delta);
            DB::table('school_pdf_reservations')->insert($key + [
                'actor_id' => $actor, 'document_id' => $documentId, 'bytes' => $bytes, 'active_delta' => $delta,
                'school_date' => $usage['school_date'], 'timezone' => $school->settings['timezone'],
                'state' => 'pending', 'expires_at' => now()->addMinutes(15),
            ]);
            $this->reservationAudit($schoolId, $actor, $documentId, 'reserved', $request);

            return ['state' => 'pending', 'replayed' => false];
        }, 3);
    }

    /** Internal only: MIME and actual byte length must come from approved server-side inspection. */
    public function settlePdf(string $schoolId, string $commandId, string $actor, int $verifiedBytes, string $verifiedMime, string $request): array
    {
        abort_unless($verifiedMime === 'application/pdf' && $verifiedBytes > 0, 422);

        return DB::transaction(function () use ($schoolId, $commandId, $actor, $verifiedBytes, $request): array {
            $school = School::whereKey($schoolId)->lockForUpdate()->firstOrFail();
            $this->requireFreeLive($school, $actor);
            $row = DB::table('school_pdf_reservations')->where(['school_id' => $schoolId, 'command_id' => $commandId]);
            $reservation = (clone $row)->lockForUpdate()->firstOrFail();
            abort_unless($reservation->actor_id === $actor, 403);
            if ($reservation->state === 'settled') {
                abort_unless($reservation->settled_bytes === $verifiedBytes, 409);

                return ['replayed' => true];
            }
            abort_unless($reservation->state === 'pending' && Carbon::parse($reservation->expires_at)->isFuture()
                && $reservation->timezone === ($school->settings['timezone'] ?? null) && $verifiedBytes <= $reservation->bytes, 409);
            $row->update(['state' => 'settled', 'settled_bytes' => $verifiedBytes]);

            return $this->admitPdf($schoolId, $reservation->document_id, $verifiedBytes, $commandId, $actor, $request);
        }, 3);
    }

    /** Release provisional capacity only; settled physical bytes require verified lawful purge. */
    public function cancelPdf(string $schoolId, string $commandId, string $actor, string $request): void
    {
        DB::transaction(function () use ($schoolId, $commandId, $actor, $request): void {
            School::whereKey($schoolId)->lockForUpdate()->firstOrFail();
            $row = DB::table('school_pdf_reservations')->where(['school_id' => $schoolId, 'command_id' => $commandId]);
            $reservation = (clone $row)->lockForUpdate()->firstOrFail();
            abort_unless($reservation->actor_id === $actor, 403);
            abort_if($reservation->state === 'settled', 409);
            if ($reservation->state === 'pending') {
                $row->update(['state' => 'cancelled']);
                $this->reservationAudit($schoolId, $actor, $reservation->document_id, 'cancelled', $request);
            }
        }, 3);
    }

    private function reservationAudit(string $school, string $actor, string $document, string $event, string $request): void
    {
        AuditEvent::create(['school_id' => $school, 'actor_user_id' => $actor,
            'action' => 'school.quota.'.$event, 'resource_id' => $document, 'request_id' => $request]);
    }

    /** Internal accounting for verified PDFs and pre-authorized, tenant-owned document bindings. */
    public function admitPdf(string $schoolId, string $documentId, int $bytes, string $commandId, string $actor, string $request): array
    {
        abort_unless(Str::isUuid($commandId) && Str::isUlid($documentId) && $bytes > 0, 422);

        return DB::transaction(function () use ($schoolId, $documentId, $bytes, $commandId, $actor, $request): array {
            $school = School::whereKey($schoolId)->lockForUpdate()->firstOrFail();
            $this->requireFreeLive($school, $actor);
            $scope = DB::table('school_pdf_admissions')->where('school_id', $schoolId);
            $prior = (clone $scope)->where('command_id', $commandId)->lockForUpdate()->first();
            $reserved = DB::table('school_pdf_reservations')->where('school_id', $schoolId)->where('command_id', $commandId)->lockForUpdate()->first();
            abort_if($reserved && ($reserved->state !== 'settled' || $reserved->document_id !== $documentId
                || $reserved->settled_bytes !== $bytes || $reserved->actor_id !== $actor), 409);
            if ($prior) {
                abort_unless($prior->document_id === $documentId && $prior->bytes === $bytes, 409);

                return ['replayed' => true];
            }
            abort_if($this->pending($school)->where('document_id', $documentId)->lockForUpdate()->first(), 409, 'Document already reserved.');
            $delta = (clone $scope)->where('document_id', $documentId)->lockForUpdate()->first() ? 0 : 1;
            $usage = $this->requireCapacity($school, $bytes, $delta);
            DB::table('school_pdf_admissions')->insert(['school_id' => $schoolId, 'command_id' => $commandId, 'document_id' => $documentId, 'bytes' => $bytes, 'active_delta' => $delta, 'school_date' => $usage['school_date']]);
            AuditEvent::create(['school_id' => $schoolId, 'actor_user_id' => $actor, 'action' => 'school.quota.pdf_admitted',
                'resource_id' => $documentId, 'request_id' => $request, 'details' => ['bytes' => $bytes, 'active_delta' => $delta]]);

            return ['replayed' => false];
        }, 3);
    }
}
