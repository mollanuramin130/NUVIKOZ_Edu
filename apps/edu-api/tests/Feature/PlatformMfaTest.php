<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlatformAssurance;
use App\Services\PlatformFirstFactor;
use App\Services\PlatformMfa;
use App\Services\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PlatformMfaTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_platform_enrollment_challenge_and_recovery_do_not_accept_replay(): void
    {
        config(['platform.synthetic_auth' => true]);
        $u = User::create(['name' => 'Synthetic platform', 'password' => 'Synthetic-Platform-Password']);
        $u->forceFill(['is_platform_admin' => true])->save();
        $r = Request::create('https://localhost/api/v1/browser/platform/mfa');
        $s = new Store('synthetic', new ArraySessionHandler(480));
        $s->start();
        $r->setLaravelSession($s);
        $r->setUserResolver(fn () => Auth::guard('web')->user());
        app(PlatformFirstFactor::class)->execute($r, ['identifier' => $u->id, 'password' => 'Synthetic-Platform-Password']);
        $mfa = app(PlatformMfa::class);
        $setup = $mfa->enroll($r, 'Synthetic-Platform-Password');
        $this->assertFalse(app(PlatformAssurance::class)->allows($r, 'platform.health'));
        $this->assertStringNotContainsString($setup['secret'], DB::table('mfa_credentials')->value('secret_encrypted'));
        $old = $s->getId();
        $code = app(Totp::class)->code($setup['secret'], now()->timestamp);
        $result = $mfa->verify($r, 'Synthetic-Platform-Password', $code, 'confirm');
        $this->assertNotSame($old, $s->getId());
        $this->assertCount(8, $result['recovery_codes']);
        $this->assertTrue(app(PlatformAssurance::class)->allows($r, 'platform.health'));
        $before = DB::table('platform_security_audits')->count();
        try {
            $mfa->verify($r, 'Synthetic-Platform-Password', $code, 'totp');
            $this->fail('TOTP replay accepted');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertSame($before, DB::table('platform_security_audits')->count());
        $recovery = $result['recovery_codes'][0];
        $this->assertTrue($mfa->verify($r, 'Synthetic-Platform-Password', $recovery, 'recovery')['verified']);
        $this->assertDatabaseHas('mfa_recovery_codes', ['code_hash' => hash('sha256', $recovery)]);
        try {
            $mfa->verify($r, 'Synthetic-Platform-Password', $recovery, 'recovery');
            $this->fail('Recovery replay accepted');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->travel(6)->minutes();
        try {
            $mfa->enroll($r, 'Synthetic-Platform-Password');
            $this->fail('Expired challenge accepted');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }
}
