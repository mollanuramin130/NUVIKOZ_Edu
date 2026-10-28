<?php

namespace App\Http\Middleware;

use App\Support\Api;
use Closure;
use Illuminate\Http\Request;

class ActiveIdentity
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()->status !== 'active') {
            return Api::error('IDENTITY_INACTIVE', 'Access denied.', 403);
        }

        return $next($request);
    }
}
