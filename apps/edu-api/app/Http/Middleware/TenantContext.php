<?php

namespace App\Http\Middleware;

use App\Support\Api;
use Closure;
use Illuminate\Http\Request;

class TenantContext
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()->must_change_password) {
            return Api::error('PASSWORD_CHANGE_REQUIRED', 'Set a new password first.', 403);
        }
        $id = $request->header('X-School-ID');
        if (! $id) {
            return Api::error('TENANT_CONTEXT_REQUIRED', 'Select a school context.', 403);
        }
        $membership = $request->user()->memberships()->where('school_id', $id)->where('status', 'active')->with('school')->first();
        if (! $membership) {
            return Api::error('NOT_FOUND', 'Resource not found.', 404);
        }
        if (in_array($membership->school->access_state, ['suspended', 'archived'], true)) {
            return Api::error('ACCESS_SUSPENDED', 'Access denied.', 403);
        }
        $request->attributes->set('membership', $membership);

        return $next($request);
    }
}
