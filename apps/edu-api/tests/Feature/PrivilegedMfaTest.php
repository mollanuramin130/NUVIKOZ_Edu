<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Services\PrivilegedMfa;
use App\Services\SchoolAccess;
use App\Services\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PrivilegedMfaTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Synthetic-Mfa-Password-01';

    private function member(string $code = 'A'): Membership
    {
        Cache::flush();
        $s = School::firstOrCreate(['code' => $code], ['name' => 'Synthetic '.$code, 'data_stage' => 'live', 'access_state' => 'live_enabled', 'entitlement_ends_at' => now()->addDay()]);
        $u = User::create(['name' => 'Synthetic MFA', 'password' => self::PASSWORD]);
        $m = Membership::create(['school_id' => $s->id, 'user_id' => $u->id, 'role' => 'school_admin', 'login_identifier' => (string) $u->id, 'status' => 'active']);
        $session = app('session')->driver();
        $session->start();
        request()->setLaravelSession($session);
        Auth::guard('web')->login($u);
        $session->put(['school_id' => $s->id, 'credential_hash' => hash('sha256', $u->password), 'issued_at' => now()->timestamp, 'seen_at' => now()->timestamp, 'mfa_challenge_expires_at' => now()->addMinutes(5)->timestamp]);

        return $m;
    }

    private function enroll(Membership $m): array
    {
        $setup = app(PrivilegedMfa::class)->enroll($m, request(), self::PASSWORD);
        $this->assertFalse(app(PrivilegedMfa::class)->verified($m, request()));
        $result = app(PrivilegedMfa::class)->verify($m, request(), self::PASSWORD, app(Totp::class)->code($setup['secret'], now()->timestamp), 'confirm');

        return [$setup['secret'], $result];
    }

    private function denied(callable $action): void
    {
        try {
            $action();
            $this->fail('MFA unexpectedly accepted');
        } catch (ValidationException|HttpException) {
            $this->assertTrue(true);
        }
    }

    public function test_confirmed_enrollment_enforces_service_assurance_and_encrypts_seed(): void
    {
        $m = $this->member();
        $this->denied(fn () => app(SchoolAccess::class)->requireAdmin($m));
        $this->denied(fn () => app(PrivilegedMfa::class)->enroll($m, request(), 'wrong'));
        [$seed, $result] = $this->enroll($m);
        $this->assertTrue(app(PrivilegedMfa::class)->verified($m, request()));
        app(SchoolAccess::class)->requireAdmin($m);
        $row = DB::table('mfa_credentials')->first();
        $this->assertNotSame($seed, $row->secret_encrypted);
        $this->assertSame($seed, Crypt::decryptString($row->secret_encrypted));
        $this->assertCount(8, $result['recovery_codes']);
        $this->assertStringNotContainsString($seed, DB::table('audit_events')->get()->toJson());
        $this->assertStringNotContainsString($result['recovery_codes'][0], DB::table('mfa_recovery_codes')->get()->toJson());
        $this->denied(fn () => app(PrivilegedMfa::class)->enroll($m, request(), self::PASSWORD));
    }

    public function test_totp_replay_bad_code_expiry_and_challenge_deadline(): void
    {
        $m = $this->member();
        [$seed] = $this->enroll($m);
        $code = app(Totp::class)->code($seed, now()->timestamp);
        $this->denied(fn () => app(PrivilegedMfa::class)->verify($m, request(), self::PASSWORD, $code, 'totp'));
        $this->denied(fn () => app(PrivilegedMfa::class)->verify($m, request(), self::PASSWORD, 'not-a-code', 'totp'));
        $this->travel(90)->seconds();
        $this->denied(fn () => app(PrivilegedMfa::class)->verify($m, request(), self::PASSWORD, $code, 'totp'));
        $this->assertTrue(app(PrivilegedMfa::class)->verify($m, request(), self::PASSWORD, app(Totp::class)->code($seed, now()->timestamp), 'totp')['verified']);
        $this->travel(6)->minutes();
        $this->denied(fn () => app(PrivilegedMfa::class)->verify($m, request(), self::PASSWORD, app(Totp::class)->code($seed, now()->timestamp), 'totp'));
    }

    public function test_pending_enrollment_and_assurance_expire_and_cannot_cross_contexts(): void
    {
        $m = $this->member();
        $setup = app(PrivilegedMfa::class)->enroll($m, request(), self::PASSWORD);
        $this->travel(11)->minutes();
        $this->denied(fn () => app(PrivilegedMfa::class)->verify($m, request(), self::PASSWORD, app(Totp::class)->code($setup['secret'], now()->timestamp), 'confirm'));
        request()->session()->put('seen_at', now()->timestamp);
        $this->enroll($m);
        request()->session()->regenerate(true);
        $this->assertFalse(app(PrivilegedMfa::class)->verified($m, request()));
        request()->session()->put('school_id', 'FOREIGN');
        $this->assertFalse(app(PrivilegedMfa::class)->verified($m, request()));
    }

    public function test_recovery_codes_are_single_use_and_replaced_sets_are_invalid(): void
    {
        $m = $this->member();
        [, $result] = $this->enroll($m);
        $code = $result['recovery_codes'][0];
        $this->assertTrue(app(PrivilegedMfa::class)->verify($m, request(), self::PASSWORD, $code, 'recovery')['verified']);
        $this->denied(fn () => app(PrivilegedMfa::class)->verify($m, request(), self::PASSWORD, $code, 'recovery'));
        $new = app(PrivilegedMfa::class)->regenerate($m, request(), self::PASSWORD);
        $this->denied(fn () => app(PrivilegedMfa::class)->verify($m, request(), self::PASSWORD, $result['recovery_codes'][1], 'recovery'));
        $this->assertCount(8, $new['recovery_codes']);
        $this->assertTrue(app(PrivilegedMfa::class)->verify($m, request(), self::PASSWORD, $new['recovery_codes'][0], 'recovery')['verified']);
        $this->travel(16)->minutes();
        request()->session()->put('seen_at', now()->timestamp);
        $this->assertFalse(app(PrivilegedMfa::class)->verified($m, request()));
        $this->denied(fn () => app(PrivilegedMfa::class)->regenerate($m, request(), self::PASSWORD));
    }

    public function test_inactive_suspended_and_pending_activation_cannot_enroll(): void
    {
        foreach (['membership', 'school', 'activation', 'identity', 'platform', 'principal'] as $case) {
            $m = $this->member('S'.$case);
            if (in_array($case, ['membership', 'principal'], true)) {
                $this->backupAdminFixture($m);
            }
            match ($case) {
                'membership' => $m->update(['status' => 'inactive']), 'school' => $m->school->update(['access_state' => 'suspended']), 'activation' => $m->user->update(['activation_required' => true]), 'identity' => $m->user->update(['status' => 'inactive']), 'platform' => $m->user->forceFill(['is_platform_admin' => true])->save(), 'principal' => $m->update(['role' => 'principal'])
            };
            $this->denied(fn () => app(PrivilegedMfa::class)->enroll($m, request(), self::PASSWORD));
            $this->assertFalse(DB::table('mfa_credentials')->where('user_id', $m->user_id)->exists());
        }
    }

    public function test_failure_rolls_back_confirmation_codes_and_assurance(): void
    {
        $m = $this->member();
        $setup = app(PrivilegedMfa::class)->enroll($m, request(), self::PASSWORD);
        AuditEvent::creating(function ($event): void {
            if ($event->action === 'mfa.enrolled') {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        try {
            app(PrivilegedMfa::class)->verify($m, request(), self::PASSWORD, app(Totp::class)->code($setup['secret'], now()->timestamp), 'confirm');
            $this->fail('Rollback failed');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic audit failure', $e->getMessage());
        }
        $this->assertNull(DB::table('mfa_credentials')->first()->confirmed_at);
        $this->assertDatabaseCount('mfa_recovery_codes', 0);
        $this->assertFalse(app(PrivilegedMfa::class)->verified($m, request()));
    }

    public function test_rate_limiting_and_wrong_identity_prevent_enrollment(): void
    {
        $m = $this->member();
        for ($i = 0; $i < 10; $i++) {
            $this->denied(fn () => app(PrivilegedMfa::class)->enroll($m, request(), 'wrong'));
        }
        try {
            app(PrivilegedMfa::class)->enroll($m, request(), self::PASSWORD);
            $this->fail('Missing throttle');
        } catch (HttpException $e) {
            $this->assertSame(429, $e->getStatusCode());
        }
        $other = $this->member('B');
        $this->denied(fn () => app(PrivilegedMfa::class)->enroll($m, request(), self::PASSWORD));
        $this->assertDatabaseCount('mfa_credentials', 0);
    }

    public function test_password_rotation_revokes_assurance_without_removing_mfa(): void
    {
        $m = $this->member();
        $this->enroll($m);
        $m->user->update(['password' => 'Synthetic-Reset-Password']);
        $this->assertFalse(app(PrivilegedMfa::class)->verified($m->fresh(), request()));
        $this->assertNotNull(DB::table('mfa_credentials')->first()->confirmed_at);
    }
}
