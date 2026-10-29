<?php

namespace App\Http\Middleware;

use App\Models\Membership;
use App\Services\PrivilegedMfa;
use App\Support\Api;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BrowserSession
{
    public function handle(Request $request, Closure $next)
    {
        $session = $request->session();
        $user = Auth::guard('web')->user();
        $membership = $user ? Membership::where('user_id', $user->id)->where('school_id', $session->get('school_id'))->where('status', 'active')->with('school')->first() : null;
        $family = $membership && in_array($membership->role->value, ['student', 'parent'], true);
        $idle = $family ? 28800 : 1800;
        if (! $user || $user->status !== 'active' || $user->is_platform_admin || ! $membership || in_array($membership->school->access_state, ['suspended', 'archived'], true) || ! hash_equals((string) $session->get('credential_hash', ''), hash('sha256', $user->password)) || time() - $session->get('issued_at', 0) >= 43200 || time() - $session->get('seen_at', 0) >= $idle) {
            Auth::guard('web')->logout();
            $session->invalidate();
            $session->regenerateToken();

            return Api::error('SESSION_EXPIRED', 'Sign in again.', 401);
        }
        $context = $request->header('X-School-ID');
        if ($context && $context !== $membership->school_id) {
            return Api::error('TENANT_CONTEXT_MISMATCH', 'School context does not match this session.', 403);
        }
        if ($user->activation_required || $user->must_change_password) {
            return Api::error('PASSWORD_CHANGE_REQUIRED', 'Complete account activation first.', 403);
        }
        $session->put('seen_at', time());
        $request->attributes->set('membership', $membership);
        $bootstrap = $request->is('api/v1/browser/me') || $request->is('api/v1/browser/security/mfa/*');
        if ($membership->role->value === 'school_admin' && ! $bootstrap && ! app(PrivilegedMfa::class)->verified($membership, $request)) {
            return Api::error('MFA_REQUIRED', 'Complete privileged MFA first.', 403);
        }

        return $next($request);
    }
}
