<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Services\AcademicCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AcademicOfferingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $code = 'OFFER'): Membership
    {
        $s = School::create(['code' => $code, 'name' => 'Synthetic', 'data_stage' => 'live', 'access_state' => 'live_enabled', 'entitlement_ends_at' => now()->addDay(), 'settings' => ['timezone' => 'Asia/Kolkata']]);
        $u = User::create(['name' => 'Synthetic', 'password' => 'Synthetic-Academic-Password']);
        $m = Membership::create(['school_id' => $s->id, 'user_id' => $u->id, 'role' => 'school_admin', 'status' => 'active', 'login_identifier' => $u->id]);
        $this->mfaFixture($m);

        return $m;
    }

    private function create(Membership $m, string $kind, array $data, ?string $key = null): array
    {
        return app(AcademicCatalog::class)->create($m, $kind, $data, $key ?? (string) Str::ulid(), 'synthetic-offering-test');
    }

    private function graph(Membership $m): array
    {
        $session = $this->create($m, 'sessions', ['name' => 'Synthetic year', 'starts_on' => '2035-04-01', 'ends_on' => '2036-03-31'])['resource'];
        $class = $this->create($m, 'classes', ['name' => 'Class 12', 'sort_order' => 12])['resource'];
        $offering = $this->create($m, 'class-offerings', ['session_id' => $session['id'], 'class_id' => $class['id']])['resource'];
        $subject = $this->create($m, 'subjects', ['name' => 'Mathematics', 'code' => 'MATH'])['resource'];

        return compact('session', 'class', 'offering', 'subject');
    }

    private function commands(array $g): array
    {
        return [
            'class-offerings' => ['session_id' => $g['session']['id'], 'class_id' => $g['class']['id']],
            'sections' => ['class_offering_id' => $g['offering']['id'], 'name' => 'Section A'],
            'subjects' => ['code' => 'PHY', 'name' => 'Physics'],
            'subject-offerings' => ['class_offering_id' => $g['offering']['id'], 'subject_id' => $g['subject']['id'], 'compulsory' => true],
        ];
    }

    private function denied(callable $work, int $code): void
    {
        try {
            $work();
            $this->fail('Invalid academic operation accepted.');
        } catch (AuthorizationException) {
            $this->assertSame(403, $code);
        } catch (ValidationException) {
            $this->assertSame(422, $code);
        } catch (HttpException $e) {
            $this->assertSame($code, $e->getStatusCode());
        }
    }

    public function test_complete_graph_preserves_real_fields_audit_and_boolean_classification(): void
    {
        $m = $this->admin();
        $g = $this->graph($m);
        $section = $this->create($m, 'sections', $this->commands($g)['sections'] + ['class_teacher_membership_id' => null])['resource'];
        $subject = $this->create($m, 'subject-offerings', $this->commands($g)['subject-offerings'])['resource'];
        $this->assertNull($section['class_teacher_membership_id']);
        $this->assertSame(1, $section['version']);
        $this->assertTrue($subject['compulsory']);
        $this->assertArrayNotHasKey('student_count', $section);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('memberships', 1);
        $this->assertDatabaseCount('audit_events', 6);
        $this->assertDatabaseCount('academic_command_receipts', 6);
        $this->assertSame($g['offering']['id'], AuditEvent::where('action', 'academic.sections.created')->firstOrFail()->details['after']['class_offering_id']);
    }

    public function test_distinct_session_offerings_preserve_section_scope_and_optional_subjects(): void
    {
        $m = $this->admin();
        $g = $this->graph($m);
        $session = $this->create($m, 'sessions', ['name' => 'Next year', 'starts_on' => '2036-04-01', 'ends_on' => '2037-03-31'])['resource'];
        $next = $this->create($m, 'class-offerings', ['session_id' => $session['id'], 'class_id' => $g['class']['id']])['resource'];
        foreach ([$g['offering'], $next] as $i => $offering) {
            $this->create($m, 'sections', ['name' => 'A', 'class_offering_id' => $offering['id']]);
            $r = $this->create($m, 'subject-offerings', ['class_offering_id' => $offering['id'], 'subject_id' => $g['subject']['id'], 'compulsory' => $i === 0]);
            $this->assertSame($i === 0, $r['resource']['compulsory']);
        }
        foreach (['class-offerings' => ['session_id' => $session['id']], 'sections' => ['class_offering_id' => $next['id']], 'subject-offerings' => ['class_offering_id' => $next['id'], 'subject_id' => $g['subject']['id']]] as $kind => $query) {
            $rows = app(AcademicCatalog::class)->listing($m, $kind, $query)['items'];
            $this->assertCount(1, $rows);
            if ($kind === 'class-offerings') {
                $this->assertSame($next['id'], $rows[0]['id']);
            } else {
                $this->assertSame($next['id'], $rows[0]['class_offering_id']);
            }
        }
    }

    public function test_each_command_replays_once_conflicts_on_changed_body_and_rejects_duplicates(): void
    {
        $m = $this->admin();
        $g = $this->graph($m);
        $other = $this->create($m, 'classes', ['name' => 'Class 11', 'sort_order' => 11])['resource'];
        $commands = $this->commands($g);
        $commands['class-offerings']['class_id'] = $other['id'];
        foreach ($commands as $kind => $data) {
            $key = (string) Str::ulid();
            $first = $this->create($m, $kind, $data, $key);
            $again = $this->create($m, $kind, array_reverse($data, true), $key);
            $this->assertSame($first['resource'], $again['resource']);
            $this->assertTrue($again['replayed']);
            $changed = $data;
            $field = array_key_first($changed);
            $changed[$field] = str_ends_with($field, '_id') ? (string) Str::ulid() : 'Changed';
            $this->denied(fn () => $this->create($m, $kind, $changed, $key), 409);
            $this->denied(fn () => $this->create($m, $kind, $data), 409);
        }
        $this->assertDatabaseCount('audit_events', 9);
        $this->assertDatabaseCount('academic_command_receipts', 9);
    }

    public function test_foreign_and_missing_references_and_list_filters_are_404_without_partial_changes(): void
    {
        $a = $this->admin('A');
        $ga = $this->graph($a);
        $b = $this->admin('B');
        $gb = $this->graph($b);
        foreach ([['class-offerings', ['session_id' => $ga['session']['id'], 'class_id' => $gb['class']['id']]], ['class-offerings', ['session_id' => $gb['session']['id'], 'class_id' => $ga['class']['id']]], ['sections', ['class_offering_id' => $ga['offering']['id'], 'name' => 'Foreign']], ['subject-offerings', ['class_offering_id' => $gb['offering']['id'], 'subject_id' => $ga['subject']['id'], 'compulsory' => true]], ['subject-offerings', ['class_offering_id' => $ga['offering']['id'], 'subject_id' => $gb['subject']['id'], 'compulsory' => false]], ['sections', ['class_offering_id' => (string) Str::ulid(), 'name' => 'Missing']]] as [$kind, $data]) {
            $this->denied(fn () => $this->create($b, $kind, $data), 404);
        }
        foreach (['class-offerings' => ['session_id' => $ga['session']['id']], 'sections' => ['class_offering_id' => $ga['offering']['id']], 'subject-offerings' => ['subject_id' => $ga['subject']['id']]] as $kind => $query) {
            $this->denied(fn () => app(AcademicCatalog::class)->listing($b, $kind, $query), 404);
        }
        $this->assertSame($gb['subject']['id'], app(AcademicCatalog::class)->listing($b, 'subjects', [])['items'][0]['id']);
        $this->assertDatabaseCount('sections', 0);
        $this->assertDatabaseCount('subject_offerings', 0);
        $this->assertDatabaseCount('audit_events', 8);
    }

    public function test_non_future_sessions_and_archived_masters_deny_creation_and_replay_but_preserve_reads(): void
    {
        $m = $this->admin();
        $g = $this->graph($m);
        $key = (string) Str::ulid();
        $data = $this->commands($g)['sections'];
        $this->create($m, 'sections', $data, $key);
        foreach (['active', 'closed', 'archived'] as $state) {
            DB::table('academic_sessions')->where('id', $g['session']['id'])->update(['state' => $state]);
            foreach (['class-offerings', 'sections', 'subject-offerings'] as $kind) {
                $this->denied(fn () => $this->create($m, $kind, $this->commands($g)[$kind]), 409);
            }
            $this->denied(fn () => $this->create($m, 'sections', $data, $key), 409);
            $this->assertCount(1, app(AcademicCatalog::class)->listing($m, 'sections', [])['items']);
        }
        DB::table('academic_sessions')->where('id', $g['session']['id'])->update(['state' => 'future', 'starts_on' => now()->subDay()->toDateString()]);
        $this->denied(fn () => $this->create($m, 'sections', $data), 409);
        DB::table('academic_sessions')->where('id', $g['session']['id'])->update(['starts_on' => '2035-04-01']);
        DB::table('classes')->where('id', $g['class']['id'])->update(['archived_at' => now()]);
        $this->denied(fn () => $this->create($m, 'sections', $data), 409);
        DB::table('classes')->where('id', $g['class']['id'])->update(['archived_at' => null]);
        DB::table('subjects')->where('id', $g['subject']['id'])->update(['archived_at' => now()]);
        $this->denied(fn () => $this->create($m, 'subject-offerings', $this->commands($g)['subject-offerings']), 409);
        $this->assertDatabaseCount('sections', 1);
        $this->assertDatabaseCount('subject_offerings', 0);
    }

    public function test_cross_session_injection_teacher_assignment_and_invalid_classification_are_rejected(): void
    {
        $m = $this->admin();
        $g = $this->graph($m);
        foreach ($this->commands($g) as $kind => $data) {
            foreach (['school_id', 'version', 'id', 'state'] as $field) {
                $this->denied(fn () => $this->create($m, $kind, $data + [$field => (string) Str::ulid()]), 422);
            }
        }
        foreach (['sections', 'subject-offerings'] as $kind) {
            foreach (['session_id', 'class_id'] as $field) {
                $this->denied(fn () => $this->create($m, $kind, $this->commands($g)[$kind] + [$field => (string) Str::ulid()]), 422);
            }
        }
        foreach ([$m->id, '', (string) Str::ulid()] as $teacher) {
            $this->denied(fn () => $this->create($m, 'sections', $this->commands($g)['sections'] + ['class_teacher_membership_id' => $teacher]), 422);
        }
        foreach (['yes', 1, 0, 'false', null] as $value) {
            $this->denied(fn () => $this->create($m, 'subject-offerings', array_replace($this->commands($g)['subject-offerings'], ['compulsory' => $value])), 422);
        }
    }

    public function test_invalid_payload_and_filter_input_is_422(): void
    {
        $m = $this->admin();
        $g = $this->graph($m);
        foreach ([['subjects', ['code' => ' ', 'name' => 'Name']], ['subjects', ['code' => str_repeat('x', 33), 'name' => 'Name']], ['subjects', ['code' => 'X', 'name' => str_repeat('x', 101)]], ['sections', ['name' => str_repeat('x', 41), 'class_offering_id' => $g['offering']['id']]], ['sections', ['name' => 'A', 'class_offering_id' => 'bad']], ['class-offerings', ['session_id' => $g['session']['id']]]] as [$kind, $data]) {
            $this->denied(fn () => $this->create($m, $kind, $data), 422);
        }
        $this->denied(fn () => app(AcademicCatalog::class)->listing($m, 'sections', ['class_offering_id' => 'bad']), 422);
        $this->denied(fn () => app(AcademicCatalog::class)->listing($m, 'subjects', ['school_id' => $m->school_id]), 422);
    }

    public function test_each_command_rechecks_mfa_roles_revocation_and_read_only_entitlement(): void
    {
        $m = $this->admin();
        $g = $this->graph($m);
        $commands = $this->commands($g);
        request()->session()->forget('mfa_assurance');
        foreach ($commands as $kind => $data) {
            $this->denied(fn () => $this->create($m, $kind, $data), 403);
        }
        $this->mfaFixture($m);
        request()->session()->put('mfa_assurance.at', now()->subSeconds(900)->timestamp);
        foreach ($commands as $kind => $data) {
            $this->denied(fn () => $this->create($m, $kind, $data), 403);
        }
        $this->mfaFixture($m);
        $m->school->update(['entitlement_ends_at' => now()]);
        foreach ($commands as $kind => $data) {
            $this->denied(fn () => $this->create($m, $kind, $data), 403);
            $this->assertIsArray(app(AcademicCatalog::class)->listing($m, $kind, [])['items']);
        }
        $m->school->update(['entitlement_ends_at' => now()->addDay()]);
        $this->backupAdminFixture($m);
        foreach (['principal', 'teacher', 'student', 'parent'] as $role) {
            $m->fresh()->update(['role' => $role]);
            foreach ($commands as $kind => $data) {
                $this->denied(fn () => $this->create($m, $kind, $data), 403);
                $this->denied(fn () => app(AcademicCatalog::class)->listing($m, $kind, []), 403);
            }
        }
        $m->fresh()->update(['role' => 'school_admin']);
        $this->mfaFixture($m->fresh());
        $m->fresh()->update(['status' => 'inactive']);
        foreach ($commands as $kind => $data) {
            $this->denied(fn () => $this->create($m, $kind, $data), 403);
        }
        $this->assertDatabaseCount('audit_events', 4);
    }

    public function test_suspended_archived_and_pending_identities_cannot_access_new_resources(): void
    {
        $m = $this->admin();
        $g = $this->graph($m);
        foreach (['suspended', 'archived'] as $state) {
            $m->school->update(['access_state' => $state]);
            foreach ($this->commands($g) as $kind => $data) {
                $this->denied(fn () => $this->create($m, $kind, $data), 403);
                $this->denied(fn () => app(AcademicCatalog::class)->listing($m, $kind, []), 403);
            }
        }
        $m->school->update(['access_state' => 'live_enabled']);
        $this->backupAdminFixture($m);
        $m->user->update(['activation_required' => true]);
        foreach ($this->commands($g) as $kind => $data) {
            $this->denied(fn () => $this->create($m, $kind, $data), 403);
        }
    }

    public function test_demo_caps_and_expired_demo_deny_writes_without_blocking_authorized_replay(): void
    {
        $m = $this->admin();
        $g = $this->graph($m);
        $m->school->update(['data_stage' => 'demo', 'access_state' => 'provisional', 'demo_ends_at' => now()->addDay()]);
        foreach ($this->commands($g) as $kind => $data) {
            config(['academic.demo_limits.'.$kind => 1]);
            if (in_array($kind, ['class-offerings', 'subjects'], true)) {
                $data[$kind === 'subjects' ? 'code' : 'class_id'] = $kind === 'subjects' ? 'NEW' : $this->create($m, 'classes', ['name' => 'Demo class', 'sort_order' => 1])['resource']['id'];
                $this->denied(fn () => $this->create($m, $kind, $data), 409);
            } else {
                $key = (string) Str::ulid();
                $this->create($m, $kind, $data, $key);
                $this->assertTrue($this->create($m, $kind, $data, $key)['replayed']);
                $this->denied(fn () => $this->create($m, $kind, $kind === 'sections' ? array_replace($data, ['name' => 'B']) : $data), 409);
            }
        }
        $m->school->update(['demo_ends_at' => now()]);
        $this->denied(fn () => $this->create($m, 'subjects', ['code' => 'EXPIRED', 'name' => 'Expired']), 403);
    }

    public function test_audit_failure_rolls_back_each_new_resource_and_receipt(): void
    {
        $m = $this->admin();
        $g = $this->graph($m);
        $other = $this->create($m, 'classes', ['name' => 'Other', 'sort_order' => 1])['resource'];
        $commands = $this->commands($g);
        $commands['class-offerings']['class_id'] = $other['id'];
        $before = DB::table('academic_command_receipts')->count();
        AuditEvent::creating(fn () => throw new \RuntimeException('Synthetic offering audit failure'));
        foreach ($commands as $kind => $data) {
            try {
                $this->create($m, $kind, $data);
                $this->fail('Audit failure ignored.');
            } catch (\RuntimeException $e) {
                $this->assertSame('Synthetic offering audit failure', $e->getMessage());
            }
        }
        $this->assertDatabaseCount('academic_command_receipts', $before);
        $this->assertDatabaseCount('audit_events', $before);
        $this->assertDatabaseCount('class_offerings', 1);
        $this->assertDatabaseCount('subjects', 1);
        $this->assertDatabaseCount('sections', 0);
        $this->assertDatabaseCount('subject_offerings', 0);
    }

    public function test_composite_foreign_keys_reject_cross_school_inserts_without_service_validation(): void
    {
        $a = $this->admin('DB_A');
        $ga = $this->graph($a);
        $b = $this->admin('DB_B');
        $gb = $this->graph($b);
        foreach ([['class_offerings', ['session_id' => $ga['session']['id'], 'class_id' => $gb['class']['id']]], ['class_offerings', ['session_id' => $gb['session']['id'], 'class_id' => $ga['class']['id']]], ['sections', ['class_offering_id' => $gb['offering']['id'], 'name' => 'Foreign']], ['sections', ['class_offering_id' => $ga['offering']['id'], 'name' => 'Foreign teacher', 'class_teacher_membership_id' => $b->id]], ['subject_offerings', ['class_offering_id' => $ga['offering']['id'], 'subject_id' => $gb['subject']['id'], 'compulsory' => true]], ['subject_offerings', ['class_offering_id' => $gb['offering']['id'], 'subject_id' => $ga['subject']['id'], 'compulsory' => false]]] as [$table, $data]) {
            try {
                DB::table($table)->insert($data + ['id' => (string) Str::ulid(), 'school_id' => $a->school_id, 'created_at' => now(), 'updated_at' => now()]);
                $this->fail('Cross-school FK accepted.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
        $this->assertDatabaseCount('class_offerings', 2);
        $this->assertDatabaseCount('sections', 0);
        $this->assertDatabaseCount('subject_offerings', 0);
    }

    public function test_database_uniqueness_and_restrictive_history_relations_are_enforced(): void
    {
        $m = $this->admin();
        $g = $this->graph($m);
        $this->create($m, 'sections', $this->commands($g)['sections']);
        $this->create($m, 'subject-offerings', $this->commands($g)['subject-offerings']);
        foreach (['class_offerings', 'sections', 'subjects', 'subject_offerings'] as $table) {
            $row = (array) DB::table($table)->first();
            $row['id'] = (string) Str::ulid();
            try {
                DB::table($table)->insert($row);
                $this->fail('Duplicate identity accepted.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
        foreach (['academic_sessions', 'classes', 'class_offerings', 'subjects'] as $table) {
            try {
                DB::table($table)->where('school_id', $m->school_id)->delete();
                $this->fail('Referenced history deleted.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
        $this->assertDatabaseCount('sections', 1);
        $this->assertDatabaseCount('subject_offerings', 1);
    }

    public function test_sections_list_is_bounded_and_cursor_retains_offering_filter(): void
    {
        $m = $this->admin();
        $g = $this->graph($m);
        for ($i = 0; $i < 26; $i++) {
            $this->create($m, 'sections', ['class_offering_id' => $g['offering']['id'], 'name' => 'Section '.$i]);
        }
        $query = ['class_offering_id' => $g['offering']['id']];
        $one = app(AcademicCatalog::class)->listing($m, 'sections', $query);
        $two = app(AcademicCatalog::class)->listing($m, 'sections', $query + ['cursor' => $one['next_cursor']]);
        $this->assertCount(25, $one['items']);
        $this->assertCount(1, $two['items']);
        $this->assertNull($two['next_cursor']);
    }
}
