<?php

namespace Tests\Feature;

use App\Http\Middleware\BrowserSession;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BrowserBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withServerVariables(['HTTPS' => 'on', 'SERVER_PORT' => 443]);
    }

    public function test_transport_and_cookie_downgrades_fail_closed(): void
    {
        config(['browser.origins' => ['https://web.edu.test']]);
        $this->withServerVariables(['HTTPS' => 'off', 'SERVER_PORT' => 80]);
        $this->getJson('http://localhost/api/v1/browser/csrf', ['Origin' => 'https://web.edu.test', 'X-Forwarded-Proto' => 'https'])->assertForbidden()->assertCookieMissing('edu_browser_session');
        $this->withServerVariables(['HTTPS' => 'on', 'SERVER_PORT' => 443]);
        config(['browser.secure' => false]);
        $this->getJson('https://localhost/api/v1/browser/csrf', ['Origin' => 'https://web.edu.test'])->assertForbidden()->assertCookieMissing('edu_browser_session');
    }

    public function test_only_explicit_proxy_ips_can_assert_https(): void
    {
        config(['browser.origins' => ['https://web.edu.test'], 'browser.trusted_proxies' => ['127.0.0.1']]);
        $this->getJson('http://localhost/api/v1/browser/csrf', ['Origin' => 'https://web.edu.test', 'X-Forwarded-Proto' => 'https'])->assertOk();
        config(['browser.trusted_proxies' => ['*']]);
        $this->getJson('http://localhost/api/v1/browser/csrf', ['Origin' => 'https://web.edu.test', 'X-Forwarded-Proto' => 'https'])->assertForbidden();
    }

    public function test_local_http_requires_explicit_loopback_exception_and_never_applies_in_production(): void
    {
        config(['browser.origins' => ['http://localhost:3000'], 'browser.secure' => false]);
        $this->getJson('http://localhost/api/v1/browser/csrf', ['Origin' => 'http://localhost:3000'])->assertForbidden();
        config(['browser.allow_local_http' => true]);
        $this->getJson('http://localhost/api/v1/browser/csrf', ['Origin' => 'http://localhost:3000'])->assertOk();
        app()->instance('env', 'production');
        $this->getJson('http://localhost/api/v1/browser/csrf', ['Origin' => 'http://localhost:3000'])->assertForbidden()->assertCookieMissing('edu_browser_session');
    }

    public function test_all_protected_browser_routes_have_both_authentication_and_current_session_guards(): void
    {
        $protected = 0;
        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/browser/') || in_array($route->uri(), ['api/v1/browser/csrf', 'api/v1/browser/login', 'api/v1/browser/logout', 'api/v1/browser/activation/consume', 'api/v1/browser/recovery/request', 'api/v1/browser/recovery/consume'], true)) {
                continue;
            }
            $protected++;
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());
            $this->assertContains(BrowserSession::class, $route->gatherMiddleware());
        }
        $this->assertGreaterThan(0, $protected);
    }

    public function test_browser_origin_is_required_and_exact(): void
    {
        config(['browser.origins' => ['https://web.edu.test']]);
        $this->getJson('https://localhost/api/v1/browser/csrf')->assertForbidden();
        $this->getJson('https://localhost/api/v1/browser/csrf', ['Origin' => 'https://web.edu.test.attacker.invalid'])->assertForbidden()->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_csrf_is_enforced_even_for_same_origin_fetch_metadata(): void
    {
        config(['browser.origins' => ['https://web.edu.test']]);
        $this->postJson('https://localhost/api/v1/browser/login', [], ['Origin' => 'https://web.edu.test', 'Sec-Fetch-Site' => 'same-origin'])->assertStatus(419)->assertHeader('Access-Control-Allow-Origin', 'https://web.edu.test');
    }

    public function test_missing_session_without_accept_header_is_json_unauthorized(): void
    {
        config(['browser.origins' => ['https://web.edu.test']]);
        $this->get('https://localhost/api/v1/browser/me', ['Origin' => 'https://web.edu.test'])->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_preflight_and_cookie_flags_are_explicit(): void
    {
        config(['browser.origins' => ['https://web.edu.test']]);
        $this->call('OPTIONS', 'https://localhost/api/v1/browser/login', [], [], [], ['HTTP_ORIGIN' => 'https://web.edu.test'])->assertNoContent()->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->getJson('https://localhost/api/v1/browser/csrf', ['Origin' => 'https://web.edu.test'])->assertOk()->assertCookie('edu_browser_session');
        $this->assertNull(config('session.domain'));
        $this->assertTrue(config('session.secure'));
        $this->assertTrue(config('session.http_only'));
    }

    public function test_revocation_tombstone_denies_a_resurrected_server_session(): void
    {
        config(['browser.origins' => ['https://web.edu.test']]);
        $school = School::create(['code' => 'TOMBSTONE', 'name' => 'Synthetic', 'data_stage' => 'live', 'access_state' => 'live_enabled']);
        $user = User::create(['name' => 'Synthetic', 'password' => 'Synthetic-Password']);
        Membership::create(['school_id' => $school->id, 'user_id' => $user->id, 'role' => 'teacher', 'login_identifier' => 'SYNTHETIC', 'status' => 'active']);
        $this->actingAs($user, 'web')->withSession(['school_id' => $school->id, 'credential_hash' => hash('sha256', $user->password), 'issued_at' => time(), 'seen_at' => time()]);
        $session = app('session')->driver()->getId();
        DB::table('session_revocations')->insert(['session_hash' => hash('sha256', $session), 'user_id' => $user->id, 'created_at' => now()]);
        $this->getJson('https://localhost/api/v1/browser/me', ['Origin' => 'https://web.edu.test'])->assertUnauthorized();
    }

    public function test_staff_family_and_absolute_session_limits_are_server_authoritative(): void
    {
        foreach ([['teacher', 1800, 100], ['student', 28800, 100], ['parent', 28800, 100], ['teacher', 10, 43200]] as $i => [$role, $idle, $age]) {
            $school = School::create(['code' => 'TIME'.$i, 'name' => 'Synthetic', 'data_stage' => 'live', 'access_state' => 'live_enabled']);
            $user = User::create(['name' => 'Synthetic', 'password' => 'Synthetic-Password']);
            Membership::create(['school_id' => $school->id, 'user_id' => $user->id, 'role' => $role, 'login_identifier' => 'SYNTHETIC', 'status' => 'active']);
            config(['browser.origins' => ['https://web.edu.test']]);
            $this->assertSame(401, $this->sessionBoundary($user, $school, $idle, max($age, $idle))->getStatusCode());
        }
    }

    public function test_family_sessions_remain_valid_beyond_staff_idle_limit_until_their_own_boundary(): void
    {
        foreach (['student', 'parent'] as $i => $role) {
            $school = School::create(['code' => 'FAMILY'.$i, 'name' => 'Synthetic', 'data_stage' => 'live', 'access_state' => 'live_enabled']);
            $user = User::create(['name' => 'Synthetic', 'password' => 'Synthetic-Password']);
            Membership::create(['school_id' => $school->id, 'user_id' => $user->id, 'role' => $role, 'login_identifier' => 'SYNTHETIC', 'status' => 'active']);
            config(['browser.origins' => ['https://web.edu.test']]);
            $this->assertSame(200, $this->sessionBoundary($user, $school, 28700, 28700)->getStatusCode());
        }
    }

    // Exercise the actual expiry middleware with an explicit store. withSession()
    // would initialize the test array handler before BrowserBoundary switches to
    // encrypted database sessions, so an unauthorized response alone proves nothing.
    private function sessionBoundary(User $user, School $school, int $idle, int $age)
    {
        $session = new Store('synthetic', new ArraySessionHandler(480));
        $session->start();
        $session->put(['school_id' => $school->id, 'credential_hash' => hash('sha256', $user->password), 'issued_at' => time() - $age, 'seen_at' => time() - $idle]);
        $request = Request::create('https://localhost/api/v1/browser/me');
        $request->setLaravelSession($session);
        Auth::guard('web')->setUser($user->fresh());

        return app(BrowserSession::class)->handle($request, fn () => response()->json(['ok' => true]));
    }
}
