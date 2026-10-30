<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Services\AcademicCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AcademicCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $school = 'ACA', string $role = 'school_admin'): Membership
    {
        $s = School::firstOrCreate(['code' => $school], ['name' => 'Synthetic academic school', 'data_stage' => 'live', 'access_state' => 'live_enabled', 'entitlement_ends_at' => now()->addDay(), 'settings' => ['timezone' => 'Asia/Kolkata']]);
        $u = User::create(['name' => 'Synthetic academic actor', 'password' => 'Synthetic-Academic-Password']);

        return Membership::create(['school_id' => $s->id, 'user_id' => $u->id, 'role' => $role, 'status' => 'active', 'login_identifier' => $u->id]);
    }

    private function create(Membership $m, string $kind = 'sessions', ?array $data = null, ?string $key = null): array
    {
        return app(AcademicCatalog::class)->create($m, $kind, $data ?? ['name' => 'Future synthetic year', 'starts_on' => now()->addYear()->toDateString(), 'ends_on' => now()->addYears(2)->toDateString()], $key ?? (string) Str::ulid(), 'synthetic-academic-request');
    }

    private function denied(callable $work, int $code): void
    {
        try {
            $work();
            $this->fail('Forbidden academic command accepted.');
        } catch (AuthorizationException) {
            $this->assertSame(403, $code);
        } catch (ValidationException) {
            $this->assertSame(422, $code);
        } catch (HttpException $e) {
            $this->assertSame($code, $e->getStatusCode());
        }
    }

    public function test_future_session_and_unrestricted_grade_catalog_have_scoped_audits(): void
    {
        $m = $this->member();
        $this->mfaFixture($m);
        $session = $this->create($m);
        $class = $this->create($m, 'classes', ['name' => 'Class 12', 'grade_label' => 'XII', 'sort_order' => 12]);
        $this->assertSame('future', $session['resource']['state']);
        $this->assertSame(1, $session['resource']['version']);
        $this->assertFalse($class['replayed']);
        $this->assertDatabaseHas('classes', ['school_id' => $m->school_id, 'name' => 'Class 12']);
        $this->assertDatabaseCount('audit_events', 2);
        $audit = AuditEvent::where('action', 'academic.sessions.created')->firstOrFail();
        $this->assertSame($m->school_id, $audit->school_id);
        $this->assertSame('Future synthetic year', $audit->details['after']['name']);
        $this->assertSame(1, $audit->details['version']);
        $this->assertNull(DB::table('academic_sessions')->value('rule_version'));
    }

    public function test_replay_is_exactly_once_but_changed_body_conflicts(): void
    {
        $m = $this->member();
        $this->mfaFixture($m);
        $key = (string) Str::ulid();
        $data = ['name' => 'Nursery', 'sort_order' => 0];
        $first = $this->create($m, 'classes', $data, $key);
        $second = $this->create($m, 'classes', array_reverse($data, true), $key);
        $this->assertSame($first['resource'], $second['resource']);
        $this->assertTrue($second['replayed']);
        $this->denied(fn () => $this->create($m, 'classes', ['name' => 'Changed', 'sort_order' => 0], $key), 409);
        $this->assertDatabaseCount('classes', 1);
        $this->assertDatabaseCount('audit_events', 1);
        $this->assertDatabaseCount('academic_command_receipts', 1);
    }

    public function test_all_non_admin_roles_are_denied_in_the_service_even_without_http(): void
    {
        foreach (['principal', 'teacher', 'student', 'parent'] as $role) {
            $m = $this->member('ACA', $role);
            $this->denied(fn () => $this->create($m), 403);
            $this->denied(fn () => app(AcademicCatalog::class)->listing($m, 'classes', []), 403);
        }
        $this->assertDatabaseCount('academic_sessions', 0);
    }

    public function test_missing_mfa_and_stale_authority_are_denied(): void
    {
        $m = $this->member();
        $this->denied(fn () => $this->create($m), 403);
        $this->mfaFixture($m);
        $this->backupAdminFixture($m);
        $m->fresh()->update(['role' => 'principal']);
        $this->denied(fn () => $this->create($m), 403);
        $this->assertDatabaseCount('academic_command_receipts', 0);
    }

    public function test_expired_school_is_read_only_and_replay_rechecks_current_entitlement(): void
    {
        $m = $this->member();
        $this->mfaFixture($m);
        $key = (string) Str::ulid();
        $this->create($m, key: $key);
        $m->school->update(['entitlement_ends_at' => now()]);
        $this->denied(fn () => $this->create($m, key: $key), 403);
        $this->assertCount(1, app(AcademicCatalog::class)->listing($m, 'sessions', [])['items']);
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_suspended_archived_inactive_and_pending_identity_cannot_access_catalog(): void
    {
        foreach (['suspended', 'archived'] as $i => $state) {
            $m = $this->member('S'.$i);
            $this->mfaFixture($m);
            $m->school->update(['access_state' => $state]);
            $this->denied(fn () => $this->create($m), 403);
            $this->denied(fn () => app(AcademicCatalog::class)->listing($m, 'sessions', []), 403);
        }
        foreach (['inactive', 'pending', 'platform'] as $state) {
            $m = $this->member($state);
            $this->mfaFixture($m);
            $this->backupAdminFixture($m);
            $m->user->forceFill(match ($state) {
                'inactive' => ['status' => 'inactive'],
                'pending' => ['activation_required' => true],
                'platform' => ['is_platform_admin' => true],
            })->save();
            $this->denied(fn () => $this->create($m), 403);
        }
        $m = $this->member('REVOKED');
        $this->backupAdminFixture($m);
        $this->mfaFixture($m);
        $m->fresh()->update(['status' => 'inactive']);
        $this->denied(fn () => $this->create($m), 403);
    }

    public function test_tenant_filtering_applies_to_listing_and_receipt_scope(): void
    {
        $a = $this->member('A');
        $b = $this->member('B');
        $key = (string) Str::ulid();
        $this->mfaFixture($a);
        $first = $this->create($a, key: $key);
        $this->mfaFixture($b);
        $this->assertSame([], app(AcademicCatalog::class)->listing($b, 'sessions', [])['items']);
        $second = $this->create($b, key: $key);
        $this->assertNotSame($first['resource']['id'], $second['resource']['id']);
        $this->assertFalse($second['replayed']);
        $this->denied(fn () => $this->create($b, 'classes', ['name' => 'Foreign', 'sort_order' => 1, 'school_id' => $a->school_id]), 422);
    }

    public function test_date_order_overlap_today_and_current_state_injection_are_rejected(): void
    {
        $m = $this->member();
        $this->mfaFixture($m);
        $data = ['name' => 'Year A', 'starts_on' => '2030-04-01', 'ends_on' => '2031-03-31'];
        $this->create($m, data: $data);
        $this->denied(fn () => $this->create($m, data: ['name' => 'Year B', 'starts_on' => '2031-03-31', 'ends_on' => '2032-03-31']), 409);
        foreach ([['ends_on' => '2030-03-31'], ['starts_on' => '2030-02-30'], ['state' => 'active'], ['rule_version' => 1], ['version' => 12]] as $bad) {
            $this->denied(fn () => $this->create($m, data: array_replace($data, $bad)), 422);
        }
        $this->travelTo(now()->setTimezone('UTC')->setDate(2029, 1, 1)->setTime(20, 0));
        $m->school->update(['entitlement_ends_at' => now()->addDay()]);
        $this->mfaFixture($m);
        $this->denied(fn () => $this->create($m, data: ['name' => 'Local today', 'starts_on' => '2029-01-02', 'ends_on' => '2029-12-31']), 422);
        $this->travelBack();
        $this->assertDatabaseCount('academic_sessions', 1);
    }

    public function test_duplicate_names_invalid_keys_and_missing_timezone_fail_closed(): void
    {
        $m = $this->member();
        $this->mfaFixture($m);
        $data = ['name' => 'Class A', 'sort_order' => 1];
        $this->create($m, 'classes', $data);
        $this->denied(fn () => $this->create($m, 'classes', $data), 409);
        foreach ([null, 'short', str_repeat('x', 161), str_repeat('x', 16).'!'] as $key) {
            $this->denied(fn () => app(AcademicCatalog::class)->create($m, 'classes', $data, $key, 'invalid'), 422);
        }
        $m->school->update(['settings' => []]);
        $this->denied(fn () => $this->create($m), 403);
        $this->assertDatabaseCount('academic_sessions', 0);
    }

    public function test_demo_quota_is_bounded_and_expired_demo_cannot_write(): void
    {
        $m = $this->member();
        $m->school->update(['data_stage' => 'demo', 'access_state' => 'provisional', 'demo_ends_at' => now()->addDay()]);
        config(['academic.demo_limits.classes' => 1]);
        $this->mfaFixture($m);
        $this->create($m, 'classes', ['name' => 'Demo class', 'sort_order' => 0]);
        $this->denied(fn () => $this->create($m, 'classes', ['name' => 'Second', 'sort_order' => 1]), 409);
        $m->school->update(['demo_ends_at' => now()]);
        $this->denied(fn () => $this->create($m), 403);
        $this->assertDatabaseCount('classes', 1);
    }

    public function test_audit_failure_rolls_back_record_and_idempotency_receipt(): void
    {
        $m = $this->member();
        $this->mfaFixture($m);
        AuditEvent::creating(fn () => throw new \RuntimeException('Synthetic academic audit failure'));
        try {
            $this->create($m);
            $this->fail('Audit failure ignored');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic academic audit failure', $e->getMessage());
        }
        $this->assertDatabaseCount('academic_sessions', 0);
        $this->assertDatabaseCount('academic_command_receipts', 0);
    }

    public function test_catalog_is_bounded_with_scoped_cursor_pagination(): void
    {
        $m = $this->member();
        $this->mfaFixture($m);
        for ($i = 0; $i < 26; $i++) {
            $this->create($m, 'classes', ['name' => 'Class '.$i, 'sort_order' => $i]);
        }
        $one = app(AcademicCatalog::class)->listing($m, 'classes', []);
        $two = app(AcademicCatalog::class)->listing($m, 'classes', ['cursor' => $one['next_cursor']]);
        $this->assertCount(25, $one['items']);
        $this->assertCount(1, $two['items']);
        $this->assertNull($two['next_cursor']);
        $this->assertNotContains($two['items'][0]['id'], array_column($one['items'], 'id'));
        $this->denied(fn () => app(AcademicCatalog::class)->listing($m, 'classes', ['school_id' => 'foreign']), 422);
    }

    public function test_browser_routes_preserve_csrf_origin_session_and_bearer_boundaries(): void
    {
        config(['browser.origins' => ['https://web.edu.test']]);
        $this->withServerVariables(['HTTPS' => 'on', 'SERVER_PORT' => 443]);
        foreach (['sessions', 'classes'] as $kind) {
            $url = 'https://localhost/api/v1/browser/academic/'.$kind;
            $this->postJson($url, [], ['Origin' => 'https://web.edu.test'])->assertStatus(419);
            $this->getJson($url, ['Origin' => 'https://hostile.invalid'])->assertForbidden();
            $this->getJson($url, ['Origin' => 'https://web.edu.test'])->assertUnauthorized();
        }
        $m = $this->member();
        $token = $m->user->createToken('Synthetic', ['school:'.$m->school_id], now()->addDay())->plainTextToken;
        $this->getJson('https://localhost/api/v1/browser/academic/sessions', ['Origin' => 'https://web.edu.test', 'Authorization' => 'Bearer '.$token])->assertForbidden();
    }
}
