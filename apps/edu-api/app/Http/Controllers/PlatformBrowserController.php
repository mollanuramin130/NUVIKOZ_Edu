<?php

namespace App\Http\Controllers;

use App\Http\Requests\MfaRequest;
use App\Http\Requests\PlatformLoginRequest;
use App\Services\PlatformAssurance;
use App\Services\PlatformFirstFactor;
use App\Services\PlatformMfa;
use App\Support\Api;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PlatformBrowserController extends Controller
{
    public function login(PlatformLoginRequest $r, PlatformFirstFactor $service)
    {
        return Api::ok($service->execute($r, $r->validated()));
    }

    public function status(Request $r, PlatformMfa $mfa)
    {
        $u = $mfa->authenticatedIdentity($r);

        return Api::ok(['user_id' => $u->id, 'enrolled' => DB::table('mfa_credentials')->where('user_id', $u->id)->whereNotNull('confirmed_at')->exists(), 'verified' => app(PlatformAssurance::class)->allows($r, 'platform.health')]);
    }

    public function enroll(MfaRequest $r, PlatformMfa $mfa)
    {
        return Api::ok($mfa->enroll($r, $r->validated('password')));
    }

    public function confirm(MfaRequest $r, PlatformMfa $mfa)
    {
        return Api::ok($mfa->verify($r, $r->validated('password'), $r->validated('code'), 'confirm'));
    }

    public function challenge(MfaRequest $r, PlatformMfa $mfa)
    {
        return Api::ok($mfa->verify($r, $r->validated('password'), $r->validated('code'), 'totp'));
    }

    public function recovery(MfaRequest $r, PlatformMfa $mfa)
    {
        return Api::ok($mfa->verify($r, $r->validated('password'), $r->validated('code'), 'recovery'));
    }

    public function identity(Request $r)
    {
        return Api::ok(['user_id' => $r->user()->id, 'context' => 'platform', 'capabilities' => ['platform.health']]);
    }

    public function logout(Request $r)
    {
        // Expired pending contexts may still be explicitly destroyed; never log out a school context here.
        if (Auth::guard('web')->check() && $r->session()->has('platform_context')) {
            DB::transaction(function () use ($r) {
                DB::table('users')->where('id', Auth::guard('web')->id())->lockForUpdate()->first();
                DB::table('session_revocations')->insertOrIgnore(['session_hash' => hash('sha256', $r->session()->getId()), 'user_id' => Auth::guard('web')->id(), 'created_at' => now()]);
                DB::table('platform_security_audits')->insert(['id' => (string) Str::ulid(), 'actor_user_id' => Auth::guard('web')->id(), 'action' => 'platform.logout', 'request_id' => substr((string) $r->attributes->get('request_id', 'platform-command'), 0, 100), 'created_at' => now()]);
            });
        }
        Auth::guard('web')->logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return Api::ok(['revoked' => true, 'csrf_token' => $r->session()->token()]);
    }
}
