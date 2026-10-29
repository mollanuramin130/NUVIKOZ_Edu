<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Totp;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PlatformBrowserTest extends TestCase
{
    use RefreshDatabase;

    private string $cookie = '';

    private array $staleCookies = [];

    private string $csrf = '';

    private function browser(string $method, string $path, array $data = []): TestResponse
    {
        // A new HTTP request must load only persisted state, never the previous store/guard.
        app('auth')->forgetGuards();
        app('auth')->shouldUse('web');
        app()->forgetInstance('auth.driver');
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        $this->withCredentials()->withServerVariables(['HTTPS' => 'on', 'SERVER_PORT' => 443])
            ->withHeader('Origin', 'https://web.edu.test')->withHeader('X-CSRF-TOKEN', $this->csrf)
            ->withUnencryptedCookie('edu_browser_session', $this->cookie);
        $response = $this->json($method, 'https://localhost/api/v1/browser/'.$path, $data);
        if ($cookie = $response->getCookie('edu_browser_session', false)) {
            $this->cookie = $cookie->getValue();
        }
        if ($token = $response->json('data.csrf_token')) {
            $this->csrf = $token;
        }

        return $response;
    }

    private function sessionData(): array
    {
        $id = app('session')->driver()->getId();
        $payload = DB::table('sessions')->where('id', $id)->value('payload');

        return json_decode(Crypt::decrypt(base64_decode($payload)), true, flags: JSON_THROW_ON_ERROR);
    }

    private function saveSession(array $data): void
    {
        DB::table('sessions')->where('id', app('session')->driver()->getId())
            ->update(['payload' => base64_encode(Crypt::encrypt(json_encode($data, JSON_THROW_ON_ERROR)))]);
    }

    private function authenticate(): User
    {
        config(['platform.synthetic_auth' => true, 'browser.origins' => ['https://web.edu.test']]);
        $u = User::create(['name' => 'Synthetic', 'password' => 'Synthetic-Platform-Password']);
        $u->forceFill(['is_platform_admin' => true])->save();
        $this->browser('GET', 'csrf')->assertOk();
        $this->staleCookies[] = $this->cookie;
        $bootstrap = app('session')->driver()->getId();
        $this->browser('POST', 'platform/login', ['identifier' => $u->id, 'password' => 'Synthetic-Platform-Password'])
            ->assertOk()->assertJsonPath('data.mfa_required', true);
        $this->staleCookies[] = $this->cookie;
        $pending = app('session')->driver()->getId();
        $this->assertNotSame($bootstrap, $pending);
        $this->assertDatabaseMissing('sessions', ['id' => $bootstrap]);
        $this->browser('GET', 'platform/identity')->assertForbidden();
        $setup = $this->browser('POST', 'platform/mfa/enrollment', ['password' => 'Synthetic-Platform-Password'])->assertOk();
        $code = app(Totp::class)->code($setup->json('data.secret'), now()->timestamp);
        $this->browser('POST', 'platform/mfa/confirm', ['password' => 'Synthetic-Platform-Password', 'code' => $code])
            ->assertOk()->assertJsonPath('data.verified', true);
        $this->assertNotSame($pending, app('session')->driver()->getId());
        $this->assertDatabaseMissing('sessions', ['id' => $pending]);
        $this->assertDatabaseHas('session_revocations', ['session_hash' => hash('sha256', $pending)]);

        return $u;
    }

    public function test_real_cookie_csrf_platform_enrollment_and_protected_identity(): void
    {
        $u = $this->authenticate();
        $id = app('session')->driver()->getId();
        $data = $this->sessionData();
        $this->assertSame(hash('sha256', $id), $data['platform_assurance']['session']);
        $response = $this->browser('GET', 'platform/identity')->assertOk()
            ->assertJsonPath('data.context', 'platform')->assertJsonPath('data.user_id', $u->id);
        $cookie = $response->getCookie('edu_browser_session');
        $this->assertSame($id, $cookie->getValue());
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertNull($cookie->getDomain());
        $this->assertSame('lax', $cookie->getSameSite());
        // The rotated CSRF token works on a subsequent mutation.
        $oldCookie = $this->cookie;
        $this->browser('POST', 'platform/logout')->assertOk();
        $this->browser('GET', 'platform/identity')->assertUnauthorized();
        $this->cookie = $oldCookie;
        $this->browser('GET', 'platform/identity')->assertUnauthorized();
    }

    public function test_persisted_assurance_is_invalidated_by_current_security_state(): void
    {
        $u = $this->authenticate();
        $data = $this->sessionData();
        $cookie = $this->cookie;
        $row = (array) DB::table('sessions')->where('id', app('session')->driver()->getId())->first();
        foreach (['binding', 'version', 'expired', 'password', 'disabled', 'revoked', 'school'] as $case) {
            DB::table('sessions')->updateOrInsert(['id' => $row['id']], $row);
            $this->cookie = $cookie;
            app('session')->driver()->setId($row['id']);
            $changed = $data;
            if ($case === 'binding') {
                $changed['platform_assurance']['session'] = hash('sha256', 'another-session');
            }
            if ($case === 'version') {
                $changed['platform_assurance']['version'] = 'obsolete';
            }
            if ($case === 'expired') {
                $changed['platform_assurance']['at'] = now()->subMinutes(15)->timestamp;
            }
            if ($case === 'password') {
                $changed['platform_context']['credential'] = 'obsolete';
            }
            if ($case === 'disabled') {
                DB::table('users')->where('id', $u->id)->update(['status' => 'suspended']);
            }
            if ($case === 'revoked') {
                DB::table('session_revocations')->insert(['session_hash' => hash('sha256', app('session')->driver()->getId()), 'user_id' => $u->id, 'created_at' => now()]);
            }
            if ($case === 'school') {
                $changed['school_id'] = 'wrong-context';
            }
            $this->saveSession($changed);
            $this->assertContains($this->browser('GET', 'platform/identity')->status(), [401, 403], $case);
            if ($case === 'disabled') {
                DB::table('users')->where('id', $u->id)->update(['status' => 'active']);
            }
            if ($case === 'revoked') {
                DB::table('session_revocations')->delete();
            }
        }
        DB::table('sessions')->updateOrInsert(['id' => $row['id']], $row);
        $this->cookie = $cookie;
        $this->browser('GET', 'platform/identity')->assertOk();
        DB::table('sessions')->where('id', app('session')->driver()->getId())->delete();
        $this->browser('GET', 'platform/identity')->assertUnauthorized();
    }

    public function test_stale_cookies_and_copied_assurance_cannot_authorize_another_session(): void
    {
        $this->authenticate();
        $activeCookie = $this->cookie;
        $data = $this->sessionData();
        foreach ($this->staleCookies as $stale) {
            $this->cookie = $stale;
            $this->browser('GET', 'platform/identity')->assertUnauthorized();
        }
        $this->browser('GET', 'csrf')->assertOk();
        $this->saveSession($data);
        $this->browser('GET', 'platform/identity')->assertForbidden();
        $this->cookie = $activeCookie;
        $this->browser('GET', 'platform/identity')->assertOk();
        $this->browser('GET', 'me')->assertUnauthorized();
    }

    public function test_actual_password_and_enrollment_changes_revoke_assurance(): void
    {
        $u = $this->authenticate();
        $version = DB::table('mfa_credentials')->where('user_id', $u->id)->value('version');
        DB::table('mfa_credentials')->where('user_id', $u->id)->update(['version' => (string) Str::ulid()]);
        $this->browser('GET', 'platform/identity')->assertForbidden();
        DB::table('mfa_credentials')->where('user_id', $u->id)->update(['version' => $version]);
        $this->browser('GET', 'platform/identity')->assertOk();
        $id = app('session')->driver()->getId();
        $u->forceFill(['password' => 'Changed-Synthetic-Password'])->save();
        $this->assertDatabaseHas('session_revocations', ['session_hash' => hash('sha256', $id)]);
        $this->browser('GET', 'platform/identity')->assertUnauthorized();
    }

    public function test_http_audit_failure_never_grants_assurance(): void
    {
        config(['platform.synthetic_auth' => true, 'browser.origins' => ['https://web.edu.test']]);
        $u = User::create(['name' => 'Synthetic', 'password' => 'Synthetic-Platform-Password']);
        $u->forceFill(['is_platform_admin' => true])->save();
        $this->browser('GET', 'csrf')->assertOk();
        $this->browser('POST', 'platform/login', ['identifier' => $u->id, 'password' => 'Synthetic-Platform-Password'])->assertOk();
        $setup = $this->browser('POST', 'platform/mfa/enrollment', ['password' => 'Synthetic-Platform-Password'])->assertOk();
        $fail = true;
        DB::listen(function (QueryExecuted $q) use (&$fail) {
            if ($fail && str_starts_with(strtolower($q->sql), 'insert into') && str_contains($q->sql, 'platform_security_audits')) {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        $code = app(Totp::class)->code($setup->json('data.secret'), now()->timestamp);
        $this->browser('POST', 'platform/mfa/confirm', ['password' => 'Synthetic-Platform-Password', 'code' => $code])->assertStatus(500);
        $fail = false;
        $this->assertNull(DB::table('mfa_credentials')->where('user_id', $u->id)->value('confirmed_at'));
        $this->assertDatabaseCount('mfa_recovery_codes', 0);
        $this->assertArrayNotHasKey('platform_assurance', $this->sessionData());
        $this->browser('GET', 'platform/identity')->assertForbidden();
    }

    public function test_activity_does_not_extend_absolute_context_or_mfa_lifetime(): void
    {
        $this->authenticate();
        $data = $this->sessionData();
        $issued = $data['platform_context']['issued_at'];
        $at = $data['platform_assurance']['at'];
        $this->travel(5)->minutes();
        $this->browser('GET', 'platform/identity')->assertOk();
        $after = $this->sessionData();
        $this->assertSame($issued, $after['platform_context']['issued_at']);
        $this->assertSame($at, $after['platform_assurance']['at']);
        $this->assertSame(now()->timestamp, $after['platform_context']['seen_at']);
        $this->travel(10)->minutes();
        $this->browser('GET', 'platform/identity')->assertForbidden();
        $data['platform_assurance']['at'] = now()->timestamp;
        $data['platform_context']['issued_at'] = now()->subHours(12)->timestamp;
        $data['platform_context']['seen_at'] = now()->timestamp;
        $this->saveSession($data);
        $this->browser('GET', 'platform/identity')->assertForbidden();
    }

    public function test_platform_mutations_preserve_origin_csrf_and_production_denial(): void
    {
        config(['platform.synthetic_auth' => true, 'browser.origins' => ['https://web.edu.test']]);
        $this->withServerVariables(['HTTPS' => 'on', 'SERVER_PORT' => 443]);
        $this->postJson('https://localhost/api/v1/browser/platform/login', [], ['Origin' => 'https://hostile.invalid'])->assertForbidden();
        $this->postJson('https://localhost/api/v1/browser/platform/login', [], ['Origin' => 'https://web.edu.test'])->assertStatus(419);
        $this->postJson('https://localhost/api/v1/browser/platform/login', [], ['Origin' => 'https://web.edu.test', 'X-CSRF-TOKEN' => 'invalid'])->assertStatus(419);
        $this->getJson('https://localhost/api/v1/browser/platform/identity', ['Origin' => 'https://web.edu.test', 'Authorization' => 'Bearer synthetic'])->assertForbidden();
        app()->instance('env', 'production');
        $response = $this->getJson('https://localhost/api/v1/browser/platform/status', ['Origin' => 'https://web.edu.test']);
        $this->assertContains($response->status(), [401, 403]);
        $this->assertDatabaseCount('platform_security_audits', 0);
        $this->assertDatabaseCount('mfa_credentials', 0);
    }
}
