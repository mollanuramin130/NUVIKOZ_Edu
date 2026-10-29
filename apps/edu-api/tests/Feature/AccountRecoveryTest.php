<?php

namespace Tests\Feature;

use App\Http\Controllers\BrowserAuthController;
use App\Http\Requests\LoginRequest;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Services\AccountActivation;
use App\Services\AccountRecovery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AccountRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function member(string $code = 'A', string $role = 'school_admin', string $identifier = 'ADMIN'): Membership
    {
        $s = School::firstOrCreate(['code' => $code], ['name' => 'Synthetic '.$code, 'data_stage' => 'live', 'access_state' => 'live_enabled', 'entitlement_ends_at' => now()->addDay()]);
        $u = User::create(['name' => 'Synthetic recovery identity', 'password' => 'Synthetic-Original-Password']);

        $member = Membership::create(['school_id' => $s->id, 'user_id' => $u->id, 'role' => $role, 'login_identifier' => $identifier, 'status' => 'active']);
        $this->mfaFixture($member);

        return $member;
    }

    private function verification(): array
    {
        return ['verification_method' => 'in_person_registered_record', 'verification_reference' => 'synthetic-case-0001', 'identity_verified' => true];
    }

    private function issued(): array
    {
        $admin = $this->member();
        $target = $this->member('A', 'teacher', 'TARGET');

        return [$admin, $target, app(AccountRecovery::class)->issue($admin, $target->id, $this->verification(), 'synthetic-issue')];
    }

    private function data(array $p, string $code = 'A'): array
    {
        return ['school_code' => $code, 'proof_id' => $p['proof_id'], 'secret' => $p['secret'], 'password' => 'Synthetic-Recovered-Password', 'password_confirmation' => 'Synthetic-Recovered-Password'];
    }

    private function denied(array $data, ?string $actor = null): void
    {
        try {
            app(AccountRecovery::class)->consume($data, 'synthetic-negative', $actor);
            $this->fail('Recovery bypass');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('recovery', $e->errors());
        }
    }

    public function test_assisted_recovery_hashing_revocation_and_single_use(): void
    {
        [$admin, $target, $proof] = $this->issued();
        $row = DB::table('account_recoveries')->first();
        $this->assertSame(hash('sha256', $proof['secret']), $row->secret_hash);
        $this->assertSame(hash('sha256', 'synthetic-case-0001'), $row->verification_reference_hash);
        $this->assertEqualsWithDelta(900, now()->diffInSeconds($row->expires_at), 2);
        $token = $target->user->createToken('synthetic', ['school:'.$target->school_id])->plainTextToken;
        DB::table('sessions')->insert(['id' => 'synthetic-old-session', 'user_id' => $target->user_id, 'payload' => '', 'last_activity' => time()]);
        // Simulate outstanding lifecycle proof for this already activated identity.
        $pending = app(AccountActivation::class)->provision($admin, ['name' => 'Pending', 'role' => 'teacher', 'login_identifier' => 'PENDING'], 'synthetic', 'synthetic-lifecycle-0001');
        DB::table('account_activations')->where('id', $pending['local_handover']['proof_id'])->update(['user_id' => $target->user_id]);
        $this->assertTrue(app(AccountRecovery::class)->consume($this->data($proof), 'synthetic-reset')['reset']);
        $this->assertTrue(Hash::check('Synthetic-Recovered-Password', $target->user->fresh()->password));
        $this->assertFalse($target->user->fresh()->activation_required);
        $this->assertSame(0, $target->user->tokens()->count());
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->user_id]);
        $this->assertNotNull(DB::table('account_activations')->first()->invalidated_at);
        $this->denied($this->data($proof));
        $this->postJson('/api/v1/auth/login', ['school_code' => 'A', 'identifier' => 'TARGET', 'password' => 'Synthetic-Original-Password'])->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['school_code' => 'A', 'identifier' => 'TARGET', 'password' => 'Synthetic-Recovered-Password'])->assertOk();
        $this->assertStringNotContainsString($proof['secret'], DB::table('audit_events')->get()->toJson());
        $this->assertDatabaseHas('audit_events', ['action' => 'recovery.assisted_issued', 'actor_user_id' => $admin->user_id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'recovery.completed', 'actor_user_id' => $target->user_id]);
    }

    public function test_public_requests_never_resolve_unknown_shared_or_unverified_contacts(): void
    {
        $this->member();
        $this->member('B');
        $service = app(AccountRecovery::class);
        $expected = $service->request(['school_code' => 'A', 'identifier' => 'ADMIN']);
        foreach ([['A', 'UNKNOWN'], ['B', 'ADMIN'], ['A', 'shared-phone'], ['A', 'unverified@example.test'], ['UNKNOWN', 'ADMIN']] as [$school, $identifier]) {
            $this->assertSame($expected, $service->request(['school_code' => $school, 'identifier' => $identifier]));
        }
        $this->assertSame(0, DB::table('account_recoveries')->count());
    }

    public function test_wrong_expired_cross_school_and_wrong_signed_in_identity_leave_state_unchanged(): void
    {
        [$admin, $target, $p] = $this->issued();
        $password = $target->user->password;
        $wrong = $this->data($p);
        $wrong['secret'] = str_repeat('0', 64);
        $this->denied($wrong);
        $this->member('B');
        $this->denied($this->data($p, 'B'));
        $this->denied($this->data($p), $admin->user_id);
        $this->travel(16)->minutes();
        $this->denied($this->data($p));
        $this->assertSame($password, $target->user->fresh()->password);
        $this->assertNull(DB::table('account_recoveries')->first()->consumed_at);
    }

    public function test_current_authority_and_identity_rechecked_and_revocation_is_permanent(): void
    {
        foreach (['issuer_role', 'issuer_status', 'issuer_password', 'target_status', 'identity', 'platform', 'suspended', 'archived', 'activation'] as $case) {
            Cache::flush();
            $a = $this->member('S'.substr(hash('sha256', $case), 0, 8));
            $t = $this->member($a->school->code, 'teacher', 'TARGET');
            $p = app(AccountRecovery::class)->issue($a, $t->id, $this->verification(), 'synthetic');
            if (in_array($case, ['issuer_role', 'issuer_status'], true)) {
                $this->backupAdminFixture($a);
            }
            match ($case) {
                'issuer_role' => $a->update(['role' => 'principal']), 'issuer_status' => $a->update(['status' => 'inactive']), 'issuer_password' => $a->user->update(['password' => 'Synthetic-Changed-Authority']),
                'target_status' => $t->update(['status' => 'inactive']), 'identity' => $t->user->update(['status' => 'inactive']), 'platform' => $t->user->forceFill(['is_platform_admin' => true])->save(),
                'suspended' => $a->school->update(['access_state' => 'suspended']), 'archived' => $a->school->update(['access_state' => 'archived']), 'activation' => $t->user->update(['activation_required' => true]),
            };
            if ($case === 'issuer_role') {
                $a->update(['role' => 'school_admin']);
            }
            $this->denied($this->data($p, $a->school->code));
        }
    }

    public function test_only_current_admin_after_documented_verification_may_issue_or_reissue(): void
    {
        [$a, $t, $p] = $this->issued();
        foreach ([$this->member('A', 'principal', 'P'), $this->member('A', 'teacher', 'T'), $this->member('B')] as $actor) {
            try {
                app(AccountRecovery::class)->issue($actor, $t->id, $this->verification(), 'denied');
                $this->fail('Unauthorized issuer');
            } catch (AuthorizationException|ModelNotFoundException) {
                $this->assertTrue(true);
            }
        }
        $this->mfaFixture($a);
        foreach ([[], ['identity_verified' => false], ['verification_method' => 'phone_guess'], ['verification_reference' => 'short']] as $change) {
            try {
                app(AccountRecovery::class)->issue($a, $t->id, $change === [] ? [] : array_merge($this->verification(), $change), 'denied');
                $this->fail('No verification');
            } catch (ValidationException) {
                $this->assertSame(1, DB::table('account_recoveries')->count());
            }
        }
        $new = app(AccountRecovery::class)->issue($a, $t->id, $this->verification(), 'reissue');
        $this->denied($this->data($p));
        $this->assertTrue(app(AccountRecovery::class)->consume($this->data($new), 'reset')['reset']);
    }

    public function test_expired_entitlement_allows_recovery_but_pending_activation_never_does(): void
    {
        $a = $this->member();
        $t = $this->member('A', 'teacher', 'TARGET');
        $a->school->update(['entitlement_ends_at' => now()->subDay()]);
        $p = app(AccountRecovery::class)->issue($a, $t->id, $this->verification(), 'expired-entitlement');
        $this->assertTrue(app(AccountRecovery::class)->consume($this->data($p), 'reset')['reset']);
        foreach (['activation_required', 'must_change_password'] as $flag) {
            $t->user->update([$flag => true]);
            try {
                app(AccountRecovery::class)->issue($a, $t->id, $this->verification(), 'pending');
                $this->fail('Activation bypass');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
    }

    public function test_validation_rate_limits_and_transaction_failure_preserve_credentials_and_proofs(): void
    {
        [$a, $t, $p] = $this->issued();
        foreach ([['password' => 'short', 'password_confirmation' => 'short'], ['password_confirmation' => 'mismatch'], ['password' => str_repeat('😀', 22), 'password_confirmation' => str_repeat('😀', 22)]] as $change) {
            try {
                app(AccountRecovery::class)->consume(array_merge($this->data($p), $change), 'bad');
                $this->fail('Weak password');
            } catch (ValidationException) {
                $this->assertNull(DB::table('account_recoveries')->first()->consumed_at);
            }
        }
        $password = $t->user->password;
        AuditEvent::creating(function (AuditEvent $event): void {
            if ($event->action === 'recovery.completed') {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        try {
            app(AccountRecovery::class)->consume($this->data($p), 'rollback');
            $this->fail('Expected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic audit failure', $e->getMessage());
        }
        $this->assertSame($password, $t->user->fresh()->password);
        $this->assertNull(DB::table('account_recoveries')->first()->consumed_at);
        $wrong = $this->data($p);
        $wrong['secret'] = str_repeat('0', 64);
        for ($i = 0; $i < 4; $i++) {
            $this->denied($wrong);
        }
        try {
            app(AccountRecovery::class)->consume($wrong, 'limited');
            $this->fail('Not throttled');
        } catch (HttpException $e) {
            $this->assertSame(429, $e->getStatusCode());
        }
    }

    public function test_production_delivery_rolls_back_and_issuance_is_scoped_throttled(): void
    {
        [$a, $t, $p] = $this->issued();
        app()->instance('env', 'production');
        try {
            app(AccountRecovery::class)->issue($a, $t->id, $this->verification(), 'production');
            $this->fail('Production delivery');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $this->assertNull(DB::table('account_recoveries')->first()->invalidated_at);
        $this->assertSame(1, DB::table('account_recoveries')->count());
        app()->instance('env', 'testing');
        app(AccountRecovery::class)->issue($a, $t->id, $this->verification(), 'third');
        try {
            app(AccountRecovery::class)->issue($a, $t->id, $this->verification(), 'limited');
            $this->fail('Not throttled');
        } catch (HttpException $e) {
            $this->assertSame(429, $e->getStatusCode());
        }
    }

    public function test_recovery_preserves_enrolled_mfa_and_requires_it_after_new_password_login(): void
    {
        $admin = $this->member();
        $target = $this->member('A', 'school_admin', 'TARGET_ADMIN');
        $before = DB::table('mfa_credentials')->where('user_id', $target->user_id)->first();
        $this->mfaFixture($admin);
        $p = app(AccountRecovery::class)->issue($admin, $target->id, $this->verification(), 'mfa-reset-test');
        Auth::guard('web')->logout();
        app('auth')->forgetGuards();
        app(AccountRecovery::class)->consume($this->data($p), 'reset-preserves-mfa');
        $after = DB::table('mfa_credentials')->where('user_id', $target->user_id)->first();
        $this->assertSame($before->secret_encrypted, $after->secret_encrypted);
        $this->assertSame($before->version, $after->version);
        $this->assertNotNull($after->confirmed_at);
        // Exercise the real login controller with a validated synthetic request. The
        // separate HTTPS suite exercises the cookie/CSRF boundary without mocking it.
        $data = ['school_code' => 'A', 'identifier' => 'TARGET_ADMIN', 'password' => 'Synthetic-Recovered-Password'];
        $request = new LoginRequest($data);
        $request->setValidator(Validator::make($data, $request->rules()));
        $request->setLaravelSession(request()->session());
        $response = app(BrowserAuthController::class)->login($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['data']['mfa_required']);

    }
}
