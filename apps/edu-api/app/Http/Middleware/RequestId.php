<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RequestId
{
    public function handle(Request $request, Closure $next)
    {
        $id = $request->header('X-Request-ID', '');
        if (! preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $id)) {
            $id = (string) Str::uuid();
        }
        $request->attributes->set('request_id', $id);
        Log::withContext(['request_id' => $id]);
        $response = $next($request);
        $response->headers->set('X-Request-ID', $id);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
