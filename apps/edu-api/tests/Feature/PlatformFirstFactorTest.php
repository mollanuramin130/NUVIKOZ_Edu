<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlatformAssurance;
use App\Services\PlatformFirstFactor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PlatformFirstFactorTest extends TestCase
{
    use RefreshDatabase;

    private function context(): Request
    {
        $r = Request::create('https://localhost/api/v1/browser/platform/login');
        $s = new Store('synthetic', new ArraySessionHandler(480));
        $s->start();
        $r->setLaravelSession($s);
        config(['platform.synthetic_auth' => true]);

        return $r;
    }

    public function test_password_login_rotates_context_but_does_not_grant_platform_authority(): void
    {
        $u = User::create(['name' => 'Synthetic', 'password' => 'Synthetic-Platform-Password']);
        $u->forceFill(['is_platform_admin' => true])->save();
        $r = $this->context();
        $old = $r->session()->getId();
        $r->session()->put('mfa_assurance', ['stale' => true]);
        $result = app(PlatformFirstFactor::class)->execute($r, ['identifier' => $u->id, 'password' => 'Synthetic-Platform-Password']);
        $this->assertTrue($result['mfa_required']);
        $this->assertNotSame($old, $r->session()->getId());
        $this->assertFalse($r->session()->has('mfa_assurance'));
        $this->assertFalse($r->session()->has('platform_assurance'));
        $this->assertFalse(app(PlatformAssurance::class)->allows($r, 'platform.health'));
        $this->assertDatabaseHas('platform_security_audits', ['actor_user_id' => $u->id, 'action' => 'platform.first_factor_verified']);
        $this->assertStringNotContainsString('Synthetic-Platform-Password', json_encode(DB::table('platform_security_audits')->first()));
    }

    public function test_unknown_and_school_identities_fail_without_audit_or_session_mutation(): void
    {
        $u = User::create(['name' => 'Synthetic school', 'password' => 'Synthetic-Platform-Password']);
        $r = $this->context();
        $old = $r->session()->getId();
        foreach ([$u->id, (string) Str::ulid()] as $id) {
            try {
                app(PlatformFirstFactor::class)->execute($r, ['identifier' => $id, 'password' => 'Synthetic-Platform-Password']);
                $this->fail('Invalid identity accepted');
            } catch (HttpException $e) {
                $this->assertSame(401, $e->getStatusCode());
            }
        }
        $this->assertSame($old, $r->session()->getId());
        $this->assertFalse($r->session()->has('platform_context'));
        $this->assertDatabaseCount('platform_security_audits', 0);
    }

    public function test_platform_audit_cannot_be_rewritten_through_query_builder(): void
    {
        $u = User::create(['name' => 'Synthetic', 'password' => 'Synthetic-Platform-Password']);
        $u->forceFill(['is_platform_admin' => true])->save();
        app(PlatformFirstFactor::class)->execute($this->context(), ['identifier' => $u->id, 'password' => 'Synthetic-Platform-Password']);
        $this->expectException(QueryException::class);
        DB::table('platform_security_audits')->update(['action' => 'forged']);
    }
}
