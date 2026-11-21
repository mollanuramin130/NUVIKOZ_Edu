<?php

namespace App\Http\Middleware;

use App\Services\PlatformAssurance;
use Closure;
use Illuminate\Http\Request;

class PlatformBrowser
{
    public function handle(Request $r, Closure $next)
    {
        abort_unless(app()->environment('testing') && config('platform.synthetic_auth') === true, 403);
        abort_if($r->session()->has('school_id') || $r->hasHeader('X-School-ID'), 403);
        if ($r->route()?->getActionMethod() === 'identity') {
            abort_unless(app(PlatformAssurance::class)->allows($r, 'platform.health'), 403);
            $context = $r->session()->get('platform_context');
            $context['seen_at'] = now()->timestamp;
            $r->session()->put('platform_context', $context);
        }

        return $next($r);
    }
}
