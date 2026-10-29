<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Services\MfaReplacement;
use App\Services\PlatformFirstFactor;
use App\Services\PlatformMfa;
use App\Services\PrivilegedMfa;
use App\Services\Totp;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MfaReplacementTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Synthetic-Replacement-Password';

    private function fixture(string $context): array
    {
        config(['platform.synthetic_auth' => true]);
        $user = User::create(['name' => 'Synthetic replacement', 'password' => self::PASSWORD]);
        $session = app('session')->driver();
        $session->start();
        $session->flush();
        request()->setLaravelSession($session);
        $member = null;
        if ($context === 'platform') {
            $user->forceFill(['is_platform_admin' => true])->save();
            app(PlatformFirstFactor::class)->execute(request(), ['identifier' => $user->id, 'password' => self::PASSWORD]);
            $setup = app(PlatformMfa::class)->enroll(request(), self::PASSWORD);
            $result = app(PlatformMfa::class)->verify(request(), self::PASSWORD, app(Totp::class)->code($setup['secret'], now()->timestamp), 'confirm');
        } else {
            $school = School::create(['code' => 'S'.$user->id, 'name' => 'Synthetic', 'data_stage' => 'live', 'access_state' => 'live_enabled', 'entitlement_ends_at' => now()->addDay()]);
            $member = Membership::create(['school_id' => $school->id, 'user_id' => $user->id, 'role' => 'school_admin', 'login_identifier' => $user->id, 'status' => 'active']);
            $this->backupAdminFixture($member);
            Auth::guard('web')->login($user);
            $session->put(['school_id' => $school->id, 'credential_hash' => hash('sha256', $user->password), 'issued_at' => now()->timestamp, 'seen_at' => now()->timestamp, 'mfa_challenge_expires_at' => now()->addMinutes(5)->timestamp]);
            $setup = app(PrivilegedMfa::class)->enroll($member, request(), self::PASSWORD);
            $result = app(PrivilegedMfa::class)->verify($member, request(), self::PASSWORD, app(Totp::class)->code($setup['secret'], now()->timestamp), 'confirm');
        }

        return [$user, $member, $setup['secret'], $result['recovery_codes']];
    }

    private function denied(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Replacement unexpectedly authorized');
        } catch (HttpException $e) {
            $this->assertContains($e->getStatusCode(), [403, 409, 422]);
        }
    }

    public function test_both_contexts_preserve_old_factor_until_atomic_confirmation_then_revoke_credentials(): void
    {
        foreach (['school', 'platform'] as $context) {
            [$user, , $oldSeed, $oldCodes] = $this->fixture($context);
            $service = app(MfaReplacement::class);
            $old = DB::table('mfa_credentials')->where('user_id', $user->id)->first();
            $user->createToken('synthetic-existing-device', ['school:synthetic']);
            $session = request()->session()->getId();
            $pending = $service->start(request(), $context, self::PASSWORD, $oldCodes[0], 'recovery');
            $this->assertSame($oldSeed, Crypt::decryptString(DB::table('mfa_credentials')->where('user_id', $user->id)->value('secret_encrypted')));
            $this->assertNotSame($pending['secret'], DB::table('mfa_replacements')->where('user_id', $user->id)->value('secret_encrypted'));
            $this->denied(fn () => $service->confirm(request(), $context, self::PASSWORD, $pending['replacement_id'], 'invalid'));
            $this->assertSame((array) $old, (array) DB::table('mfa_credentials')->where('user_id', $user->id)->first());
            $result = $service->confirm(request(), $context, self::PASSWORD, $pending['replacement_id'], app(Totp::class)->code($pending['secret'], now()->timestamp));
            $new = DB::table('mfa_credentials')->where('user_id', $user->id)->first();
            $this->assertSame($pending['secret'], Crypt::decryptString($new->secret_encrypted));
            $this->assertNotSame($old->version, $new->version);
            $this->assertCount(8, $result['recovery_codes']);
            $audit = DB::table($context === 'platform' ? 'platform_security_audits' : 'audit_events')->get()->toJson();
            $this->assertStringNotContainsString($pending['secret'], $audit);
            $this->assertStringNotContainsString($result['recovery_codes'][0], $audit);
            $this->assertTrue($result['reauthentication_required']);
            $this->assertDatabaseMissing('mfa_replacements', ['user_id' => $user->id]);
            $this->assertDatabaseMissing('mfa_recovery_codes', ['code_hash' => hash('sha256', $oldCodes[1])]);
            $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
            $this->assertDatabaseHas('session_revocations', ['session_hash' => hash('sha256', $session)]);
            $this->assertNull(Auth::guard('web')->id());
            $this->assertFalse(request()->session()->has('mfa_assurance'));
            $this->assertFalse(request()->session()->has('platform_assurance'));
            $this->denied(fn () => $service->confirm(request(), $context, self::PASSWORD, $pending['replacement_id'], app(Totp::class)->code($pending['secret'], now()->timestamp)));
        }
    }

    public function test_wrong_replayed_and_expired_proofs_cannot_remove_an_existing_factor(): void
    {
        foreach (['school', 'platform'] as $context) {
            [$user, , $seed, $codes] = $this->fixture($context);
            $service = app(MfaReplacement::class);
            $before = (array) DB::table('mfa_credentials')->where('user_id', $user->id)->first();
            $this->denied(fn () => $service->start(request(), $context, 'wrong', $codes[0], 'recovery'));
            $this->denied(fn () => $service->start(request(), $context, self::PASSWORD, 'invalid', 'totp'));
            $this->denied(fn () => $service->start(request(), $context, self::PASSWORD, app(Totp::class)->code($seed, now()->timestamp), 'totp'));
            $pending = $service->start(request(), $context, self::PASSWORD, $codes[0], 'recovery');
            $this->denied(fn () => $service->start(request(), $context, self::PASSWORD, $codes[1], 'recovery'));
            DB::table('mfa_replacements')->where('user_id', $user->id)->update(['expires_at' => now()->subSecond()]);
            $this->denied(fn () => $service->confirm(request(), $context, self::PASSWORD, $pending['replacement_id'], app(Totp::class)->code($pending['secret'], now()->timestamp)));
            $this->denied(fn () => $service->start(request(), $context, self::PASSWORD, $codes[0], 'recovery'));
            $this->assertSame($before, (array) DB::table('mfa_credentials')->where('user_id', $user->id)->first());
        }
    }

    public function test_current_password_identity_session_and_context_are_rechecked(): void
    {
        foreach (['school', 'platform'] as $context) {
            [$user, $member, , $codes] = $this->fixture($context);
            $service = app(MfaReplacement::class);
            $pending = $service->start(request(), $context, self::PASSWORD, $codes[0], 'recovery');
            $this->denied(fn () => $service->confirm(request(), $context === 'school' ? 'platform' : 'school', self::PASSWORD, $pending['replacement_id'], '123456'));
            $session = request()->session();
            $oldId = $session->getId();
            $session->regenerate();
            $this->denied(fn () => $service->confirm(request(), $context, self::PASSWORD, $pending['replacement_id'], '123456'));
            $session->setId($oldId);
            if ($member) {
                request()->headers->set('X-School-ID', 'foreign-school');
                $this->denied(fn () => $service->confirm(request(), $context, self::PASSWORD, $pending['replacement_id'], '123456'));
                request()->headers->remove('X-School-ID');
                $member->update(['role' => 'principal']);
                $this->denied(fn () => $service->confirm(request(), $context, self::PASSWORD, $pending['replacement_id'], '123456'));
            } else {
                app()->instance('env', 'production');
                $this->denied(fn () => $service->confirm(request(), $context, self::PASSWORD, $pending['replacement_id'], '123456'));
                app()->instance('env', 'testing');
            }
            $user->update(['password' => 'Changed-Synthetic-Password']);
            $this->denied(fn () => $service->confirm(request(), $context, self::PASSWORD, $pending['replacement_id'], '123456'));
        }
    }

    public function test_totp_possession_is_consumed_once_without_removing_the_old_authenticator(): void
    {
        foreach (['school', 'platform'] as $context) {
            [$user, , $seed] = $this->fixture($context);
            $service = app(MfaReplacement::class);
            $code = app(Totp::class)->code($seed, (intdiv(now()->timestamp, 30) + 1) * 30);
            $service->start(request(), $context, self::PASSWORD, $code, 'totp');
            DB::table('mfa_replacements')->where('user_id', $user->id)->update(['expires_at' => now()->subSecond()]);
            $this->denied(fn () => $service->start(request(), $context, self::PASSWORD, $code, 'totp'));
            $this->assertSame($seed, Crypt::decryptString(DB::table('mfa_credentials')->where('user_id', $user->id)->value('secret_encrypted')));
        }
    }

    public function test_inactive_identity_membership_and_suspended_school_deny_valid_pending_proof(): void
    {
        foreach (['identity', 'membership', 'school', 'teacher'] as $change) {
            [$user, $member, , $codes] = $this->fixture('school');
            $service = app(MfaReplacement::class);
            $pending = $service->start(request(), 'school', self::PASSWORD, $codes[0], 'recovery');
            if ($change === 'identity') {
                $user->update(['status' => 'inactive']);
            }
            if ($change === 'membership') {
                $member->update(['status' => 'inactive']);
            }
            if ($change === 'school') {
                $member->school->update(['access_state' => 'suspended']);
            }
            if ($change === 'teacher') {
                $member->update(['role' => 'teacher']);
            }
            $this->denied(fn () => $service->confirm(request(), 'school', self::PASSWORD, $pending['replacement_id'], app(Totp::class)->code($pending['secret'], now()->timestamp)));
            $this->assertDatabaseHas('mfa_replacements', ['id' => $pending['replacement_id']]);
        }
    }

    public function test_pending_proof_cannot_move_between_two_authorized_school_memberships(): void
    {
        [$user, , , $codes] = $this->fixture('school');
        $service = app(MfaReplacement::class);
        $pending = $service->start(request(), 'school', self::PASSWORD, $codes[0], 'recovery');
        $other = School::create(['code' => 'OTHER', 'name' => 'Synthetic other', 'data_stage' => 'live', 'access_state' => 'live_enabled', 'entitlement_ends_at' => now()->addDay()]);
        Membership::create(['school_id' => $other->id, 'user_id' => $user->id, 'role' => 'school_admin', 'login_identifier' => 'OTHER', 'status' => 'active']);
        request()->session()->put('school_id', $other->id);
        $this->denied(fn () => $service->confirm(request(), 'school', self::PASSWORD, $pending['replacement_id'], app(Totp::class)->code($pending['secret'], now()->timestamp)));
        $this->assertDatabaseHas('mfa_replacements', ['id' => $pending['replacement_id']]);
    }

    public function test_audit_failure_rolls_back_factor_consumption_and_replacement_activation(): void
    {
        foreach (['school', 'platform'] as $context) {
            [$user, , , $codes] = $this->fixture($context);
            $service = app(MfaReplacement::class);
            $fail = true;
            DB::listen(function (QueryExecuted $q) use (&$fail) {
                if ($fail && str_starts_with(strtolower($q->sql), 'insert into') && (str_contains($q->sql, 'audit_events') || str_contains($q->sql, 'platform_security_audits'))) {
                    throw new \RuntimeException('Synthetic audit failure');
                }
            });
            try {
                $service->start(request(), $context, self::PASSWORD, $codes[0], 'recovery');
                $this->fail('Audit fault must abort issuance');
            } catch (\RuntimeException $e) {
                $this->assertSame('Synthetic audit failure', $e->getMessage());
            }
            $this->assertDatabaseMissing('mfa_replacements', ['user_id' => $user->id]);
            $this->assertNull(DB::table('mfa_recovery_codes')->where('code_hash', hash('sha256', $codes[0]))->value('consumed_at'));
            $fail = false;
            $pending = $service->start(request(), $context, self::PASSWORD, $codes[0], 'recovery');
            $before = (array) DB::table('mfa_credentials')->where('user_id', $user->id)->first();
            $fail = true;
            try {
                $service->confirm(request(), $context, self::PASSWORD, $pending['replacement_id'], app(Totp::class)->code($pending['secret'], now()->timestamp));
                $this->fail('Audit fault must abort activation');
            } catch (\RuntimeException $e) {
                $this->assertSame('Synthetic audit failure', $e->getMessage());
            }
            $fail = false;
            $this->assertSame($before, (array) DB::table('mfa_credentials')->where('user_id', $user->id)->first());
            $this->assertDatabaseHas('mfa_replacements', ['id' => $pending['replacement_id']]);
            $this->assertSame($user->id, Auth::guard('web')->id());
        }
    }
}
