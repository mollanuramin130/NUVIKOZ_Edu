<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\School;
use Illuminate\Support\Carbon;
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
            'quota' => $grant?->policy === self::FREE['version'] ? self::FREE + $this->usage($school) : null,
            'history' => $this->events($school)->latest('sequence')->limit(25)->get(['action', 'actor_id', 'sequence', 'starts_at', 'ends_at', 'policy']),
        ];
    }

    // Caller holds the school lock and revalidates Platform MFA.
    public function change(School $school, object $link, string $actor, array $command, string $request): array
    {
        $previous = $this->events($school)->where('command_id', $command['command_id'])->lockForUpdate()->first();
        $action = $command['action'];
        $days = $command['term_days'] ?? null;
        if ($previous) {
            abort_unless($previous->actor_id === $actor && $previous->action === $action && $previous->term_days === $days, 409);

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
        $school->save();
        $this->events($school)->insert([
            'id' => $id, 'school_id' => $school->id, 'actor_id' => $actor,
            'command_id' => $command['command_id'], 'sequence' => ($latest->sequence ?? 0) + 1,
            'action' => $action, 'policy' => $policy, 'term_days' => $days, 'starts_at' => $now, 'ends_at' => $end,
        ]);
        AuditEvent::create(['school_id' => $school->id, 'actor_user_id' => $actor, 'action' => 'school.entitlement.'.$action,
            'resource_id' => $id, 'request_id' => $request, 'details' => ['previous_access' => $before, 'access' => $school->access_state, 'policy' => $policy]]);

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

    /** Internal accounting for verified PDFs and pre-authorized, tenant-owned document bindings. */
    public function admitPdf(string $schoolId, string $documentId, int $bytes, string $commandId, string $actor, string $request): array
    {
        abort_unless(Str::isUuid($commandId) && Str::isUlid($documentId) && $bytes > 0, 422);

        return DB::transaction(function () use ($schoolId, $documentId, $bytes, $commandId, $actor, $request): array {
            $school = School::whereKey($schoolId)->lockForUpdate()->firstOrFail();
            abort_unless($school->data_stage === 'live' && $school->access_state === 'live_enabled', 403);
            $member = Membership::where('school_id', $schoolId)->where('user_id', $actor)->where('status', 'active')->with('user')->first();
            abort_unless($member && $member->user->status === 'active' && ! $member->user->is_platform_admin
                && ! $member->user->activation_required && ! $member->user->must_change_password, 403);
            $grant = $this->grant($school, true);
            abort_unless($grant?->policy === self::FREE['version'] && $this->current($grant) && $school->entitlement_ends_at?->isFuture(), 403);
            $scope = DB::table('school_pdf_admissions')->where('school_id', $schoolId);
            $prior = (clone $scope)->where('command_id', $commandId)->lockForUpdate()->first();
            if ($prior) {
                abort_unless($prior->document_id === $documentId && $prior->bytes === $bytes, 409);

                return ['replayed' => true];
            }
            $delta = (clone $scope)->where('document_id', $documentId)->lockForUpdate()->first() ? 0 : 1;
            $usage = $this->usage($school, true);
            abort_unless($usage['school_date'], 409, 'School timezone is required.');
            abort_if($bytes > self::FREE['max_pdf_bytes'], 422, 'PDF size limit exceeded.');
            abort_if(self::FREE['max_active_pdfs'] < $usage['active'] + $delta, 409, 'Active PDF limit exceeded.');
            abort_if($usage['daily'] >= self::FREE['max_daily_pdfs'], 409, 'Daily PDF limit exceeded.');
            abort_if(self::FREE['max_total_bytes'] < $usage['bytes'] + $bytes, 409, 'School storage limit exceeded.');
            DB::table('school_pdf_admissions')->insert(['school_id' => $schoolId, 'command_id' => $commandId, 'document_id' => $documentId, 'bytes' => $bytes, 'active_delta' => $delta, 'school_date' => $usage['school_date']]);
            AuditEvent::create(['school_id' => $schoolId, 'actor_user_id' => $actor, 'action' => 'school.quota.pdf_admitted',
                'resource_id' => $documentId, 'request_id' => $request, 'details' => ['bytes' => $bytes, 'active_delta' => $delta]]);

            return ['replayed' => false];
        }, 3);
    }
}
