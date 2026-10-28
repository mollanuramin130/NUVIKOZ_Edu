<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Services\ProvisionStaff;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    use RefreshDatabase;

    private function school(string $code = 'A', string $stage = 'live'): School
    {
        return School::create(['code' => $code, 'name' => 'Synthetic school '.$code, 'data_stage' => $stage, 'access_state' => 'live_enabled', 'entitlement_ends_at' => now()->addDays(30), 'demo_ends_at' => now()->addDays(30)]);
    }

    private function member(School $s, string $role = 'school_admin', string $identifier = 'TEST'): Membership
    {
        $u = User::create(['name' => 'Synthetic account', 'password' => 'Synthetic-Test-Password']);

        return Membership::create(['school_id' => $s->id, 'user_id' => $u->id, 'role' => $role, 'login_identifier' => $identifier, 'status' => 'active']);
    }

    private function auth(Membership $m): static
    {
        app('auth')->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$m->user->createToken('test', ['*'], now()->addHours(12))->plainTextToken, 'X-School-ID' => $m->school_id, 'Idempotency-Key' => 'synthetic-request-key-0001']);
    }

    public function test_health_has_standard_contract_and_safe_request_id(): void
    {
        $this->getJson('/api/v1/health', ['X-Request-ID' => 'test-request'])->assertOk()->assertJsonPath('data.status', 'ok')->assertJsonPath('meta.request_id', 'test-request')->assertHeader('X-Request-ID', 'test-request');
        $this->getJson('/api/v1/health', ['X-Request-ID' => 'bad value'])->assertOk()->assertJsonMissingPath('data.password');
    }

    public function test_readiness_checks_database(): void
    {
        $this->getJson('/api/v1/health/ready')->assertOk();
    }

    public function test_unknown_route_has_json_error_contract(): void
    {
        $this->getJson('/api/v1/nope')->assertNotFound()->assertJsonStructure(['error' => ['code', 'message', 'fields'], 'meta' => ['request_id']]);
    }

    public function test_auth_required(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_validation_rejects_unknown_and_missing_fields(): void
    {
        $this->postJson('/api/v1/auth/login', ['school_id' => 'forged'])->assertUnprocessable()->assertJsonValidationErrors(['school_code', 'identifier', 'password', 'school_id'], 'error.fields');
    }

    public function test_student_login_without_email_and_token_authentication(): void
    {
        $m = $this->member($this->school(), 'student');
        $r = $this->postJson('/api/v1/auth/login', ['school_code' => 'A', 'identifier' => 'TEST', 'password' => 'Synthetic-Test-Password'])->assertOk();
        $this->withToken($r->json('data.token'))->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $m->user_id)->assertJsonMissingPath('data.password');
    }

    public function test_bad_login_is_generic(): void
    {
        $this->postJson('/api/v1/auth/login', ['school_code' => 'unknown', 'identifier' => 'TEST', 'password' => 'wrong'])->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_expired_token_denied(): void
    {
        $m = $this->member($this->school());
        $token = $m->user->createToken('expired', ['*'], now()->subMinute())->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_logout_revokes_token(): void
    {
        $m = $this->member($this->school());
        $token = $m->user->createToken('test')->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_context_is_explicit(): void
    {
        $m = $this->member($this->school());
        $this->withToken($m->user->createToken('test')->plainTextToken)->getJson('/api/v1/schools/'.$m->school_id)->assertForbidden()->assertJsonPath('error.code', 'TENANT_CONTEXT_REQUIRED');
    }

    public function test_tenant_a_cannot_guess_b_resource_or_header(): void
    {
        $a = $this->member($this->school());
        $b = $this->school('B');
        $this->auth($a)->getJson('/api/v1/schools/'.$a->school_id)->assertOk();
        $this->getJson('/api/v1/schools/'.$b->id)->assertNotFound();
        $this->withHeader('X-School-ID', $b->id)->getJson('/api/v1/schools/'.$b->id)->assertNotFound();
    }

    public function test_inactive_membership_is_rechecked(): void
    {
        $m = $this->member($this->school());
        $this->auth($m);
        $m->update(['status' => 'inactive']);
        $this->getJson('/api/v1/schools/'.$m->school_id)->assertNotFound();
    }

    public function test_school_role_cannot_access_platform(): void
    {
        $m = $this->member($this->school());
        $this->auth($m)->getJson('/api/v1/platform/health')->assertForbidden();
    }

    public function test_platform_identity_has_separate_guard(): void
    {
        $m = $this->member($this->school());
        $m->user->forceFill(['is_platform_admin' => true])->save();
        $this->auth($m)->getJson('/api/v1/platform/health')->assertOk();
    }

    public function test_principal_cannot_provision_accounts(): void
    {
        $m = $this->member($this->school(), 'principal');
        $this->auth($m)->postJson('/api/v1/accounts', ['name' => 'Synthetic teacher', 'login_identifier' => 'NEW', 'role' => 'teacher'])->assertForbidden();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_admin_provision_is_hashed_audited_and_requires_password_change(): void
    {
        $m = $this->member($this->school());
        $r = $this->auth($m)->postJson('/api/v1/accounts', ['name' => 'Synthetic teacher', 'login_identifier' => 'NEW', 'role' => 'teacher'])->assertCreated();
        $new = Membership::where('login_identifier', 'NEW')->firstOrFail();
        $this->assertTrue(Hash::check($r->json('data.one_time_password'), $new->user->password));
        $this->assertTrue($new->user->must_change_password);
        $this->assertDatabaseCount('audit_events', 1);
        $this->auth($new)->getJson('/api/v1/schools/'.$new->school_id)->assertForbidden()->assertJsonPath('error.code', 'PASSWORD_CHANGE_REQUIRED');
    }

    public function test_privilege_injection_and_platform_role_rejected(): void
    {
        $m = $this->member($this->school());
        $this->auth($m)->postJson('/api/v1/accounts', ['name' => 'Bad', 'login_identifier' => 'NEW', 'role' => 'teacher', 'is_platform_admin' => true])->assertUnprocessable();
        $this->postJson('/api/v1/accounts', ['name' => 'Bad', 'login_identifier' => 'NEW', 'role' => 'platform_super_admin'])->assertUnprocessable();
    }

    public function test_provisioning_replay_identifier_cannot_create_duplicate_user(): void
    {
        $m = $this->member($this->school());
        $d = ['name' => 'Teacher', 'login_identifier' => 'NEW', 'role' => 'teacher'];
        $this->auth($m)->postJson('/api/v1/accounts', $d)->assertCreated();
        $this->postJson('/api/v1/accounts', $d)->assertCreated()->assertJsonPath('data.replayed', true)->assertJsonPath('data.one_time_password', null);
        $this->withHeader('Idempotency-Key', 'synthetic-request-key-0002')->postJson('/api/v1/accounts', $d)->assertUnprocessable();
        $this->assertDatabaseCount('users', 2);
    }

    public function test_expiry_blocks_provisioning_but_preserves_scoped_reads(): void
    {
        $m = $this->member($this->school());
        $m->school->update(['entitlement_ends_at' => now()->subDay()]);
        $this->auth($m)->postJson('/api/v1/accounts', ['name' => 'Teacher', 'login_identifier' => 'NEW', 'role' => 'teacher'])->assertForbidden();
        $this->getJson('/api/v1/schools/'.$m->school_id)->assertOk();
    }

    public function test_demo_uploads_and_live_homework_upload_are_denied(): void
    {
        $m = $this->member($this->school('A', 'demo'));
        $this->auth($m)->postJson('/api/v1/storage/reservations', ['base64_file' => 'invalid'])->assertForbidden()->assertJsonPath('error.code', 'DEMO_UPLOAD_FORBIDDEN');
        $this->postJson('/api/v1/homework/example/attachments')->assertForbidden();
        $m->school->update(['data_stage' => 'live']);
        $this->postJson('/api/v1/homework/example/attachments')->assertForbidden()->assertJsonPath('error.code', 'FEATURE_DISABLED');
    }

    public function test_composite_foreign_key_rejects_cross_school_permission(): void
    {
        $m = $this->member($this->school());
        $b = $this->school('B');
        $this->expectException(QueryException::class);
        DB::table('membership_permissions')->insert(['id' => (string) Str::ulid(), 'school_id' => $b->id, 'membership_id' => $m->id, 'capability' => 'school.read', 'allowed' => true]);
    }

    public function test_internal_ids_cannot_be_reassigned(): void
    {
        $m = $this->member($this->school());
        $this->expectException(LogicException::class);
        $m->school_id = $this->school('B')->id;
        $m->save();
    }

    public function test_audit_model_is_append_only(): void
    {
        $m = $this->member($this->school());
        $e = AuditEvent::create(['school_id' => $m->school_id, 'actor_user_id' => $m->user_id, 'action' => 'test', 'resource_id' => $m->id, 'request_id' => 'test']);
        $this->expectException(LogicException::class);
        $e->delete();
    }

    public function test_password_change_revokes_devices(): void
    {
        $m = $this->member($this->school());
        $this->auth($m)->postJson('/api/v1/auth/password', ['current_password' => 'Synthetic-Test-Password', 'password' => 'New-Synthetic-Password', 'password_confirmation' => 'New-Synthetic-Password'])->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertTrue(Hash::check('New-Synthetic-Password', $m->user->fresh()->password));
    }

    public function test_service_rechecks_deactivated_actor(): void
    {
        $m = $this->member($this->school());
        Membership::whereKey($m->id)->update(['status' => 'inactive']);
        $this->expectException(AuthorizationException::class);
        app(ProvisionStaff::class)->execute($m, ['name' => 'Teacher', 'login_identifier' => 'NEW', 'role' => 'teacher'], 'test');
    }

    public function test_expired_demo_cannot_write_but_can_read(): void
    {
        $m = $this->member($this->school('A', 'demo'));
        $m->school->update(['demo_ends_at' => now()->subDay()]);
        $this->auth($m)->postJson('/api/v1/accounts', ['name' => 'Teacher', 'login_identifier' => 'NEW', 'role' => 'teacher'])->assertForbidden();
        $this->getJson('/api/v1/schools/'.$m->school_id)->assertOk();
    }

    public function test_all_ingestion_methods_are_closed_in_demo(): void
    {
        $m = $this->member($this->school('A', 'demo'));
        $this->auth($m);
        foreach (['/imports/files', '/ingestion/remote', '/ingestion/base64'] as $route) {
            $this->postJson('/api/v1'.$route, ['remote_url' => 'https://example.invalid/no-fetch'])->assertForbidden()->assertJsonPath('error.code', 'DEMO_UPLOAD_FORBIDDEN');
        }
    }

    public function test_idempotency_key_cannot_be_reused_for_different_body(): void
    {
        $m = $this->member($this->school());
        $this->auth($m)->postJson('/api/v1/accounts', ['name' => 'First', 'login_identifier' => 'NEW', 'role' => 'teacher'])->assertCreated();
        $this->postJson('/api/v1/accounts', ['name' => 'Second', 'login_identifier' => 'OTHER', 'role' => 'teacher'])->assertConflict();
        $this->assertDatabaseCount('users', 2);
    }

    public function test_provisioning_requires_an_idempotency_header(): void
    {
        $m = $this->member($this->school());
        $this->auth($m)->withHeader('Idempotency-Key', '')->postJson('/api/v1/accounts', ['name' => 'Teacher', 'login_identifier' => 'NEW', 'role' => 'teacher'])->assertUnprocessable();
    }
}
