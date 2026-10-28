<?php

namespace App\Http\Middleware;

use App\Support\Api;
use App\Support\BrowserOrigin;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;

class BrowserBoundary
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->is('api/v1/browser/*')) {
            return $next($request);
        }
        $origin = $request->header('Origin', '');
        if (! BrowserOrigin::allowed($origin, config('browser.origins', []))) {
            return Api::error('ORIGIN_DENIED', 'Untrusted browser origin.', 403);
        }
        if ($request->hasHeader('Authorization')) {
            return Api::error('BROWSER_SESSION_REQUIRED', 'Use browser session authentication.', 403);
        }
        config(['session.driver' => 'database', 'session.cookie' => 'edu_browser_session', 'session.domain' => null, 'session.path' => '/', 'session.http_only' => true, 'session.secure' => config('browser.secure'), 'session.same_site' => 'lax', 'session.lifetime' => 480, 'session.encrypt' => true]);
        if ($request->isMethod('OPTIONS')) {
            $response = response('', 204);
        } else {
            try {
                $response = $next($request);
            } catch (\Throwable $exception) {
                app(ExceptionHandler::class)->report($exception);
                $response = app(ExceptionHandler::class)->render($request, $exception);
            }
        }
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, X-CSRF-TOKEN, X-School-ID, Accept');
        $response->setVary('Origin', false);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
