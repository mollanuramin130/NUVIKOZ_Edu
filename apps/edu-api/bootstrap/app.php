<?php

use App\Http\Middleware\ActiveIdentity;
use App\Http\Middleware\BrowserBoundary;
use App\Http\Middleware\BrowserCsrf;
use App\Http\Middleware\EduTrustProxies;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\TenantContext;
use App\Support\Api;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/browser.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->remove(TrustProxies::class);
        $middleware->prepend([RequestId::class, EduTrustProxies::class, BrowserBoundary::class]);
        $middleware->redirectGuestsTo(fn () => null);
        $middleware->web(replace: [PreventRequestForgery::class => BrowserCsrf::class]);
        $middleware->alias(['identity.active' => ActiveIdentity::class, 'tenant' => TenantContext::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'secret', 'code']);
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => true);
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($e instanceof ValidationException) {
                return Api::error('VALIDATION_FAILED', 'The input was invalid.', 422, $e->errors());
            }
            if ($e instanceof AuthenticationException) {
                return Api::error('UNAUTHENTICATED', 'Authentication required.', 401);
            }
            if ($e instanceof AuthorizationException) {
                return Api::error('FORBIDDEN', 'Access denied.', 403);
            }
            if ($e instanceof ModelNotFoundException) {
                return Api::error('NOT_FOUND', 'Resource not found.', 404);
            }
            if ($e instanceof HttpExceptionInterface) {
                return Api::error('HTTP_'.$e->getStatusCode(), 'The request could not be completed.', $e->getStatusCode())->withHeaders($e->getHeaders());
            }

            return Api::error('INTERNAL_ERROR', 'The request could not be completed.', 500);
        });
    })->create();
