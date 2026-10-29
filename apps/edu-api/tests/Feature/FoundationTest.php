<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Services\AccountActivation;
use App\Services\ProvisionStaff;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
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
        Auth::guard('web')->logout();
        app('auth')->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$m->user->createToken('test', ['school:'.$m->school_id], now()->addHours(12))->plainTextToken, 'X-School-ID' => $m->school_id, 'Idempotency-Key' => 'synthetic-request-key-0001']);
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
        $this->withHeader('X-School-ID', $b->id)->getJson('/api/v1/schools/'.$b->id)->assertForbidden();
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
        $this->auth($m)->getJson('/api/v1/platform/health')->assertForbidden();
        app('auth')->forgetGuards();
        $token = $m->user->createToken('platform', ['platform:admin'], now()->addHours(12))->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/platform/health')->assertForbidden();
    }

    public function test_principal_cannot_provision_accounts(): void
    {
        $m = $this->member($this->school(), 'principal');
        $this->auth($m)->postJson('/api/v1/accounts', ['name' => 'Synthetic teacher', 'login_identifier' => 'NEW', 'role' => 'teacher'])->assertForbidden();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_admin_provision_requires_activation_and_never_hands_over_a_password(): void
    {
        $m = $this->member($this->school());
        $this->auth($m)->postJson('/api/v1/accounts', ['name' => 'Synthetic teacher', 'login_identifier' => 'NEW', 'role' => 'teacher'])->assertForbidden();
        $this->assertDatabaseCount('users', 1);
        $this->mfaFixture($m);
        $result = app(ProvisionStaff::class)->execute($m, ['name' => 'Synthetic teacher', 'login_identifier' => 'NEW', 'role' => 'teacher'], 'mfa-provision', 'synthetic-request-key-0001');
        $this->assertArrayNotHasKey('one_time_password', $result);
        $new = Membership::findOrFail($result['membership_id']);
        $this->assertTrue($new->user->activation_required);
        $this->assertTrue($new->user->must_change_password);
        $this->assertDatabaseHas('account_activations', ['membership_id' => $new->id, 'secret_hash' => hash('sha256', $result['local_handover']['secret'])]);
    }

    public function test_legacy_provision_cannot_bypass_activation_with_device_login_or_password_rotation(): void
    {
        $admin = $this->member($this->school());
        $this->auth($admin)->postJson('/api/v1/accounts', ['name' => 'Pending', 'login_identifier' => 'PENDING', 'role' => 'teacher'])->assertForbidden();
        $this->assertDatabaseCount('users', 1);
        $this->mfaFixture($admin);
        $result = app(ProvisionStaff::class)->execute($admin, ['name' => 'Pending', 'login_identifier' => 'PENDING', 'role' => 'teacher'], 'provision', 'synthetic-request-key-0001');
        $target = Membership::findOrFail($result['membership_id']);
        $target->user->password = 'Known-Synthetic-Initial-Password';
        $target->user->save();
        app('auth')->forgetGuards();
        $this->flushHeaders();
        $this->postJson('/api/v1/auth/login', ['school_code' => 'A', 'identifier' => 'PENDING', 'password' => 'Known-Synthetic-Initial-Password'])->assertUnauthorized();
        $this->auth($target)->postJson('/api/v1/auth/password', ['current_password' => 'Known-Synthetic-Initial-Password', 'password' => 'New-Synthetic-Password', 'password_confirmation' => 'New-Synthetic-Password'])->assertForbidden();
        $this->assertTrue($target->user->fresh()->activation_required);
        $this->mfaFixture($admin);
        $proof = app(AccountActivation::class)->issue($admin, $target->id, 'reissue');
        app(AccountActivation::class)->consume(['school_code' => 'A', 'proof_id' => $proof['proof_id'], 'secret' => $proof['secret'], 'password' => 'Activated-Synthetic-Password', 'password_confirmation' => 'Activated-Synthetic-Password'], 'activate');
        app('auth')->forgetGuards();
        $this->flushHeaders();
        $this->postJson('/api/v1/auth/login', ['school_code' => 'A', 'identifier' => 'PENDING', 'password' => 'Activated-Synthetic-Password'])->assertOk();
    }

    public function test_legacy_and_direct_provisioning_roll_back_when_production_delivery_is_unavailable(): void
    {
        $admin = $this->member($this->school());
        app()->instance('env', 'production');
        $this->auth($admin)->postJson('/api/v1/accounts', ['name' => 'Blocked', 'login_identifier' => 'BLOCKED', 'role' => 'teacher'])->assertForbidden();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('account_activations', 0);
        $this->assertDatabaseCount('audit_events', 0);
        $this->assertDatabaseCount('idempotency_records', 0);
        $this->mfaFixture($admin);
        try {
            app(ProvisionStaff::class)->execute($admin, ['name' => 'Blocked', 'login_identifier' => 'DIRECT', 'role' => 'teacher'], 'direct-test', 'synthetic-direct-key-0001');
            $this->fail('Production provisioning allowed');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $this->assertDatabaseCount('users', 1);
    }

    public function test_privilege_injection_and_platform_role_rejected(): void
    {
        $m = $this->member($this->school());
        $this->auth($m)->postJson('/api/v1/accounts', ['name' => 'Bad', 'login_identifier' => 'NEW', 'role' => 'teacher', 'is_platform_admin' => true])->assertForbidden();
        $this->postJson('/api/v1/accounts', ['name' => 'Bad', 'login_identifier' => 'NEW', 'role' => 'platform_super_admin'])->assertForbidden();
    }

    public function test_provisioning_replay_identifier_cannot_create_duplicate_user(): void
    {
        $m = $this->member($this->school());
        $this->mfaFixture($m);
        $d = ['name' => 'Teacher', 'login_identifier' => 'NEW', 'role' => 'teacher'];
        $service = app(ProvisionStaff::class);
        $service->execute($m, $d, 'first', 'synthetic-request-key-0001');
        $replay = $service->execute($m, $d, 'repeat', 'synthetic-request-key-0001');
        $this->assertTrue($replay['replayed']);
        $this->assertArrayNotHasKey('local_handover', $replay);
        $this->assertDatabaseCount('users', 2);
        $this->expectException(ValidationException::class);
        $service->execute($m, $d, 'duplicate', 'synthetic-request-key-0002');
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
        $this->mfaFixture($m);
        app(ProvisionStaff::class)->execute($m, ['name' => 'First', 'login_identifier' => 'NEW', 'role' => 'teacher'], 'first', 'synthetic-request-key-0001');
        try {
            app(ProvisionStaff::class)->execute($m, ['name' => 'Second', 'login_identifier' => 'OTHER', 'role' => 'teacher'], 'conflict', 'synthetic-request-key-0001');
            $this->fail('Replay mutated');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseCount('users', 2);
    }

    public function test_provisioning_requires_an_idempotency_header(): void
    {
        $m = $this->member($this->school());
        $this->mfaFixture($m);
        try {
            app(ProvisionStaff::class)->execute($m, ['name' => 'Teacher', 'login_identifier' => 'NEW', 'role' => 'teacher'], 'missing', null);
            $this->fail('Missing idempotency accepted');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertDatabaseCount('users', 1);
    }

    public function test_login_token_cannot_switch_to_another_membership_school(): void
    {
        $a = $this->member($this->school());
        $b = $this->school('B');
        Membership::create(['school_id' => $b->id, 'user_id' => $a->user_id, 'role' => 'school_admin', 'login_identifier' => 'OTHER', 'status' => 'active']);
        $response = $this->postJson('/api/v1/auth/login', ['school_code' => 'A', 'identifier' => 'TEST', 'password' => 'Synthetic-Test-Password'])->assertOk();
        $token = $response->json('data.token');
        app('auth')->forgetGuards();
        $this->withToken($token)->withHeader('X-School-ID', $a->school_id)->getJson('/api/v1/schools/'.$a->school_id)->assertOk();
        $this->withHeader('X-School-ID', $b->id)->getJson('/api/v1/schools/'.$b->id)->assertForbidden()->assertJsonPath('error.code', 'TOKEN_SCHOOL_MISMATCH');
    }

    public function test_suspended_school_cannot_issue_a_login_token(): void
    {
        $m = $this->member($this->school());
        $m->school->update(['access_state' => 'suspended']);
        $this->postJson('/api/v1/auth/login', ['school_code' => 'A', 'identifier' => 'TEST', 'password' => 'Synthetic-Test-Password'])->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_platform_user_cannot_issue_tokens_through_school_login(): void
    {
        $m = $this->member($this->school());
        $m->user->forceFill(['is_platform_admin' => true])->save();
        $this->postJson('/api/v1/auth/login', ['school_code' => 'A', 'identifier' => 'TEST', 'password' => 'Synthetic-Test-Password'])->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_service_rejects_role_escalation_without_form_request(): void
    {
        $m = $this->member($this->school());
        $this->mfaFixture($m);
        $this->expectException(ValidationException::class);
        app(ProvisionStaff::class)->execute($m, ['name' => 'Bad', 'login_identifier' => 'BAD', 'role' => 'school_admin'], 'test', 'synthetic-request-key');
    }

    public function test_service_rechecks_password_change_requirement(): void
    {
        $m = $this->member($this->school());
        $m->user->update(['must_change_password' => true]);
        $this->expectException(AuthorizationException::class);
        app(ProvisionStaff::class)->execute($m, ['name' => 'Teacher', 'login_identifier' => 'NEW', 'role' => 'teacher'], 'test', 'synthetic-request-key');
    }

    public function test_query_builder_cannot_rewrite_audit_events(): void
    {
        $m = $this->member($this->school());
        $event = AuditEvent::create(['school_id' => $m->school_id, 'actor_user_id' => $m->user_id, 'action' => 'original', 'resource_id' => $m->id, 'request_id' => 'test']);
        $this->expectException(QueryException::class);
        DB::table('audit_events')->where('id', $event->id)->update(['action' => 'forged']);
    }

    public function test_query_builder_cannot_delete_audit_events(): void
    {
        $m = $this->member($this->school());
        $event = AuditEvent::create(['school_id' => $m->school_id, 'actor_user_id' => $m->user_id, 'action' => 'original', 'resource_id' => $m->id, 'request_id' => 'test']);
        $this->expectException(QueryException::class);
        DB::table('audit_events')->where('id', $event->id)->delete();
    }

    public function test_method_error_retains_allow_header(): void
    {
        $this->postJson('/api/v1/health')->assertStatus(405)->assertHeader('Allow')->assertJsonPath('error.code', 'HTTP_405');
    }
}
