<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrowserBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_origin_is_required_and_exact(): void
    {
        config(['browser.origins' => ['https://web.edu.test']]);
        $this->getJson('/api/v1/browser/csrf')->assertForbidden();
        $this->getJson('/api/v1/browser/csrf', ['Origin' => 'https://web.edu.test.attacker.invalid'])->assertForbidden()->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_csrf_is_enforced_even_for_same_origin_fetch_metadata(): void
    {
        config(['browser.origins' => ['https://web.edu.test']]);
        $this->postJson('/api/v1/browser/login', [], ['Origin' => 'https://web.edu.test', 'Sec-Fetch-Site' => 'same-origin'])->assertStatus(419)->assertHeader('Access-Control-Allow-Origin', 'https://web.edu.test');
    }

    public function test_missing_session_without_accept_header_is_json_unauthorized(): void
    {
        config(['browser.origins' => ['https://web.edu.test']]);
        $this->get('/api/v1/browser/me', ['Origin' => 'https://web.edu.test'])->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_preflight_and_cookie_flags_are_explicit(): void
    {
        config(['browser.origins' => ['https://web.edu.test']]);
        $this->call('OPTIONS', '/api/v1/browser/login', [], [], [], ['HTTP_ORIGIN' => 'https://web.edu.test'])->assertNoContent()->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->getJson('/api/v1/browser/csrf', ['Origin' => 'https://web.edu.test'])->assertOk()->assertCookie('edu_browser_session');
        $this->assertNull(config('session.domain'));
        $this->assertTrue(config('session.secure'));
        $this->assertTrue(config('session.http_only'));
    }
}
