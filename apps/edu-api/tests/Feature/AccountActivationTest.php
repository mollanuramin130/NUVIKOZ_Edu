<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Services\AccountActivation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AccountActivationTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $code = 'A', string $role = 'school_admin', string $identifier = 'ADMIN'): Membership
    {
        $s = School::firstOrCreate(['code' => $code], ['name' => 'Synthetic '.$code, 'data_stage' => 'live', 'access_state' => 'live_enabled', 'entitlement_ends_at' => now()->addDays(30)]);
        $u = User::create(['name' => 'Synthetic user', 'password' => 'Synthetic-Admin-Password']);

        $member = Membership::create(['school_id' => $s->id, 'user_id' => $u->id, 'role' => $role, 'login_identifier' => $identifier, 'status' => 'active']);
        $this->mfaFixture($member);

        return $member;
    }

    private function pending(?Membership $admin = null): array
    {
        $admin ??= $this->member();
        $result = app(AccountActivation::class)->provision($admin, ['name' => 'Synthetic teacher', 'login_identifier' => 'NEW', 'role' => 'teacher'], 'activation-test', 'synthetic-activation-0001');

        return [$admin, Membership::findOrFail($result['membership_id']), $result['local_handover']];
    }

    private function data(array $proof, string $code = 'A'): array
    {
        return ['school_code' => $code, 'proof_id' => $proof['proof_id'], 'secret' => $proof['secret'], 'password' => 'Synthetic-New-Password-123', 'password_confirmation' => 'Synthetic-New-Password-123'];
    }

    private function invalid(array $data): void
    {
        try {
            app(AccountActivation::class)->consume($data, 'negative-test');
            $this->fail('Activation unexpectedly succeeded');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('activation', $e->errors());
        }
    }

    public function test_authorized_activation_is_hashed_single_use_audited_and_login_handover_works(): void
    {
        [$admin,$target,$proof] = $this->pending();
        $user = $target->user;
        $row = DB::table('account_activations')->first();
        $this->assertSame(hash('sha256', $proof['secret']), $row->secret_hash);
        $this->assertNotSame($proof['secret'], $row->secret_hash);
        $this->assertEqualsWithDelta(1800, now()->diffInSeconds($row->expires_at), 2);
        config(['browser.origins' => ['https://web.edu.test']]);
        $this->postJson('/api/v1/auth/login', ['school_code' => 'A', 'identifier' => 'NEW', 'password' => 'Synthetic-New-Password-123'], ['Origin' => 'https://web.edu.test', 'X-CSRF-TOKEN' => 'csrf-test'])->assertUnauthorized();
        $user->createToken('pending', ['school:'.$admin->school_id]);
        $result = app(AccountActivation::class)->consume($this->data($proof), 'activation-complete');
        $this->assertTrue($result['activated']);
        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertFalse($user->activation_required);
        $this->assertTrue(Hash::check('Synthetic-New-Password-123', $user->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertNotNull(DB::table('account_activations')->first()->consumed_at);
        $this->invalid($this->data($proof));
        $this->postJson('/api/v1/auth/login', ['school_code' => 'A', 'identifier' => 'NEW', 'password' => 'Synthetic-New-Password-123'], ['Origin' => 'https://web.edu.test', 'X-CSRF-TOKEN' => 'csrf-test'])->assertOk()->assertJsonPath('data.must_change_password', false);
        $this->assertDatabaseHas('audit_events', ['action' => 'activation.issued', 'actor_user_id' => $admin->user_id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'activation.completed', 'actor_user_id' => $target->user_id]);
    }

    public function test_wrong_expired_and_cross_school_proofs_leave_all_state_unchanged(): void
    {
        [$admin,$target,$proof] = $this->pending();
        $original = $target->user->password;
        $audit = AuditEvent::count();
        $wrong = $this->data($proof);
        $wrong['secret'] = str_repeat('0', 64);
        $this->invalid($wrong);
        $this->member('B');
        $this->invalid($this->data($proof, 'B'));
        $this->travel(31)->minutes();
        $this->invalid($this->data($proof));
        $this->assertSame($original, $target->user->fresh()->password);
        $this->assertTrue($target->user->fresh()->activation_required);
        $this->assertNull(DB::table('account_activations')->first()->consumed_at);
        $this->assertSame($audit, AuditEvent::count());
    }

    public function test_current_authority_and_target_changes_block_consumption(): void
    {
        foreach (['issuer_role', 'issuer_inactive', 'issuer_password', 'target_inactive', 'identity_inactive', 'platform', 'school_suspended', 'school_archived', 'school_provisional', 'entitlement'] as $case) {
            $admin = $this->member('S'.substr(hash('sha256', $case), 0, 8));
            [$admin,$target,$proof] = $this->pending($admin);
            if (in_array($case, ['issuer_role', 'issuer_inactive'], true)) {
                $this->backupAdminFixture($admin);
            }
            match ($case) {
                'issuer_role' => $admin->update(['role' => 'principal']), 'issuer_inactive' => $admin->update(['status' => 'inactive']), 'issuer_password' => $admin->user->update(['password' => 'Changed-Authority-Password']),
                'target_inactive' => $target->update(['status' => 'inactive']), 'identity_inactive' => $target->user->update(['status' => 'inactive']),
                'platform' => $target->user->forceFill(['is_platform_admin' => true])->save(),
                'school_suspended' => $admin->school->update(['access_state' => 'suspended']), 'school_archived' => $admin->school->update(['access_state' => 'archived']), 'school_provisional' => $admin->school->update(['access_state' => 'provisional']),
                'entitlement' => $admin->school->update(['entitlement_ends_at' => now()->subDay()]),
            };
            $this->invalid($this->data($proof, $admin->school->code));
            $this->assertTrue($target->user->fresh()->activation_required);
        }
    }

    public function test_principal_teacher_and_foreign_school_cannot_provision_or_reissue(): void
    {
        [$admin,$target,$proof] = $this->pending();
        foreach ([$this->member('A', 'principal', 'PRINCIPAL'), $this->member('A', 'teacher', 'TEACHER'), $this->member('B')] as $actor) {
            foreach (['provision', 'issue'] as $operation) {
                try {
                    if ($operation === 'provision' && $actor->school_id !== $admin->school_id) {
                        continue;
                    }
                    if ($operation === 'provision') {
                        app(AccountActivation::class)->provision($actor, ['name' => 'Invalid', 'role' => 'teacher', 'login_identifier' => 'NO'], 'negative', 'synthetic-negative-0001');
                    } else {
                        app(AccountActivation::class)->issue($actor, $target->id, 'negative');
                    } $this->fail('Unauthorized operation');
                } catch (AuthorizationException|ModelNotFoundException $e) {
                    $this->assertTrue(true);
                }
            }
            try {
                app(AccountActivation::class)->consume($this->data($proof), 'negative', $actor->user_id);
                $this->fail('Actor activated another account');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('activation', $e->errors());
            }
        }
    }

    public function test_reissue_invalidates_old_proof_and_idempotent_provision_never_redelivers(): void
    {
        [$admin,$target,$proof] = $this->pending();
        $replay = app(AccountActivation::class)->provision($admin, ['name' => 'Synthetic teacher', 'login_identifier' => 'NEW', 'role' => 'teacher'], 'replay', 'synthetic-activation-0001');
        $this->assertTrue($replay['replayed']);
        $this->assertArrayNotHasKey('local_handover', $replay);
        $new = app(AccountActivation::class)->issue($admin, $target->id, 'reissue')['local_handover'];
        $this->invalid($this->data($proof));
        $this->assertTrue(app(AccountActivation::class)->consume($this->data($new), 'success')['activated']);
        $this->assertDatabaseHas('audit_events', ['action' => 'activation.invalidated']);
        try {
            app(AccountActivation::class)->issue($admin, $target->id, 'after-activation');
            $this->fail('Reissue after activation');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_password_validation_and_unknown_authority_fields_never_change_state(): void
    {
        [$admin,$target,$proof] = $this->pending();
        foreach ([['password' => 'short', 'password_confirmation' => 'short'], ['password' => str_repeat('😀', 22), 'password_confirmation' => str_repeat('😀', 22)], ['password_confirmation' => 'Mismatch'], ['is_platform_admin' => true], ['purpose' => 'recovery']] as $change) {
            try {
                app(AccountActivation::class)->consume(array_merge($this->data($proof), $change), 'invalid');
                $this->fail('Invalid payload accepted');
            } catch (ValidationException) {
                $this->assertTrue($target->user->fresh()->must_change_password);
            }
        }
        foreach (['student', 'parent', 'school_admin', 'platform_super_admin'] as $role) {
            try {
                app(AccountActivation::class)->provision($admin, ['name' => 'No profile', 'role' => $role, 'login_identifier' => 'BAD'], 'invalid', 'synthetic-denied-role-0001');
                $this->fail('Forbidden role provisioned');
            } catch (ValidationException) {
                $this->assertSame(2, User::count());
            }
        }
    }

    public function test_pending_activation_cannot_use_mobile_password_bypass(): void
    {
        [$admin,$target] = $this->pending();
        $user = $target->user;
        $user->password = 'Known-Synthetic-Initial-Password';
        $user->save();
        $this->postJson('/api/v1/auth/login', ['school_code' => 'A', 'identifier' => 'NEW', 'password' => 'Known-Synthetic-Initial-Password'])->assertUnauthorized();
        Auth::guard('web')->logout();
        app('auth')->forgetGuards();
        $token = $user->createToken('synthetic', ['school:'.$admin->school_id])->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/auth/password', ['current_password' => 'Known-Synthetic-Initial-Password', 'password' => 'Synthetic-New-Password-123', 'password_confirmation' => 'Synthetic-New-Password-123'])->assertForbidden();
        $this->assertTrue($user->fresh()->activation_required);
    }

    public function test_restored_authority_cannot_resurrect_a_revoked_proof(): void
    {
        [$admin,$target,$proof] = $this->pending();
        $this->backupAdminFixture($admin);
        $admin->update(['role' => 'principal']);
        $admin->update(['role' => 'school_admin']);
        $this->assertNotNull(DB::table('account_activations')->first()->invalidated_at);
        $this->invalid($this->data($proof));
    }

    public function test_activation_audit_rows_cannot_be_rewritten(): void
    {
        [,,$proof] = $this->pending();
        app(AccountActivation::class)->consume($this->data($proof), 'audit-integrity');
        $this->expectException(QueryException::class);
        DB::table('audit_events')->where('action', 'activation.completed')->update(['action' => 'tampered']);
    }

    public function test_failure_after_password_write_rolls_back_every_activation_change(): void
    {
        [, $target, $proof] = $this->pending();
        $password = $target->user->password;
        AuditEvent::creating(function (AuditEvent $event): void {
            if ($event->action === 'activation.completed') {
                throw new \RuntimeException('Synthetic audit storage failure');
            }
        });
        try {
            app(AccountActivation::class)->consume($this->data($proof), 'rollback-test');
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic audit storage failure', $e->getMessage());
        }
        $this->assertSame($password, $target->user->fresh()->password);
        $this->assertTrue($target->user->fresh()->activation_required);
        $this->assertNull(DB::table('account_activations')->where('id', $proof['proof_id'])->value('consumed_at'));
    }

    public function test_delivery_is_nonproduction_only_and_failure_rolls_back_provisioning(): void
    {
        $admin = $this->member();
        app()->instance('env', 'production');
        try {
            $this->pending($admin);
            $this->fail('Production handover');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $this->assertSame(1, User::count());
        $this->assertSame(0, DB::table('account_activations')->count());
        $this->assertSame(0, AuditEvent::count());
    }

    public function test_staging_staff_handover_is_sealed_and_uses_existing_activation_for_both_roles(): void
    {
        $code = 'EDU-STAGE-ACADEMIC';
        $admin = $this->member($code);
        $admin->school->update(['data_stage' => 'demo', 'access_state' => 'provisional', 'demo_ends_at' => now()->addDay()]);
        $keypair = sodium_crypto_box_keypair();
        config([
            'staging-bootstrap.staff_activation_school' => $code,
            'staging-bootstrap.staff_activation_public_key' => bin2hex(sodium_crypto_box_publickey($keypair)),
        ]);
        app()->instance('env', 'staging');
        $service = app(AccountActivation::class);

        foreach (['principal', 'teacher'] as $role) {
            $staff = ['name' => 'Synthetic '.$role, 'login_identifier' => strtoupper($role), 'role' => $role];
            $key = 'staging-provision-'.$role;
            $result = $service->provision($admin, $staff, 'staging-provision', $key);
            $target = Membership::findOrFail($result['membership_id']);
            $this->assertSame($role, $target->role->value);
            $this->assertTrue($target->user->activation_required);

            $sealed = base64_decode($result['sealed_handover'], true);
            $this->assertFalse(sodium_crypto_box_seal_open($sealed, sodium_crypto_box_keypair()));
            $proof = json_decode(sodium_crypto_box_seal_open($sealed, $keypair), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($code, $proof['school_code']);
            $this->assertStringNotContainsString($proof['secret'], json_encode($result, JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString($proof['secret'], AuditEvent::all()->toJson());
            $this->assertArrayNotHasKey('local_handover', $result);
            $this->assertDatabaseHas('account_activations', ['id' => $proof['proof_id'], 'secret_hash' => hash('sha256', $proof['secret'])]);
            $replay = $service->provision($admin, $staff, 'staging-retry', $key);
            $this->assertTrue($replay['replayed']);
            $this->assertArrayNotHasKey('sealed_handover', $replay);

            $replacement = $service->issue($admin, $target->id, 'staging-reissue');
            $this->assertArrayNotHasKey('local_handover', $replacement);
            $freshProof = json_decode(sodium_crypto_box_seal_open(base64_decode($replacement['sealed_handover'], true), $keypair), true, flags: JSON_THROW_ON_ERROR);
            $this->invalid($this->data($proof, $code));
            $this->invalid($this->data($freshProof, 'OTHER-SCHOOL'));
            $this->assertTrue($service->consume($this->data($freshProof, $code), 'staging-activation')['activated']);
            $this->assertFalse($target->user->fresh()->activation_required);
            $this->invalid($this->data($freshProof, $code));
            $this->assertDatabaseHas('audit_events', ['action' => 'activation.completed', 'resource_id' => $freshProof['proof_id']]);
        }

        config(['browser.origins' => ['https://web.edu.test'], 'browser.secure' => true]);
        $this->withServerVariables(['HTTPS' => 'on', 'SERVER_PORT' => 443]);
        foreach (['principal', 'teacher'] as $role) {
            Auth::forgetGuards();
            $origin = ['Origin' => 'https://web.edu.test'];
            $handshake = $this->getJson('https://localhost/api/v1/browser/csrf', $origin)->assertOk();
            $this->withCredentials()
                ->withCookie('edu_browser_session', $handshake->getCookie('edu_browser_session')->getValue())
                ->postJson('https://localhost/api/v1/browser/login', [
                    'school_code' => $code,
                    'identifier' => strtoupper($role),
                    'password' => 'Synthetic-New-Password-123',
                ], $origin + ['X-CSRF-TOKEN' => $handshake->json('data.csrf_token')])
                ->assertOk()
                ->assertJsonPath('data.role', $role)
                ->assertJsonPath('data.school_id', $admin->school_id);
        }
    }

    public function test_staging_delivery_requires_exact_school_valid_key_and_current_admin_mfa(): void
    {
        $code = 'EDU-STAGE-ACADEMIC';
        $admin = $this->member($code);
        $admin->school->update(['data_stage' => 'demo', 'access_state' => 'provisional', 'demo_ends_at' => now()->addDay()]);
        $staff = ['name' => 'Synthetic principal', 'login_identifier' => 'PRINCIPAL', 'role' => 'principal'];
        $provision = fn () => app(AccountActivation::class)->provision($admin, $staff, 'staging-denied', 'staging-denied-key');
        $validKey = bin2hex(sodium_crypto_box_publickey(sodium_crypto_box_keypair()));
        app()->instance('env', 'staging');

        foreach ([
            ['EDU-STAGE-OTHER', $validKey],
            [$code, null],
            [$code, 'malformed'],
            [$code, str_repeat('0', 64)],
        ] as [$configuredSchool, $configuredKey]) {
            config([
                'staging-bootstrap.staff_activation_school' => $configuredSchool,
                'staging-bootstrap.staff_activation_public_key' => $configuredKey,
            ]);
            try {
                $provision();
                $this->fail('Delivery unexpectedly succeeded');
            } catch (HttpException $e) {
                $this->assertSame(503, $e->getStatusCode());
            }
            $this->assertDatabaseCount('memberships', 1);
            $this->assertDatabaseCount('users', 1);
            $this->assertDatabaseCount('account_activations', 0);
            $this->assertDatabaseCount('audit_events', 0);
            $this->assertDatabaseCount('idempotency_records', 0);
        }

        config(['staging-bootstrap.staff_activation_public_key' => $validKey]);
        request()->session()->forget('mfa_assurance');
        try {
            $provision();
            $this->fail('Provisioning unexpectedly succeeded without MFA');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertDatabaseCount('memberships', 1);
    }
}
