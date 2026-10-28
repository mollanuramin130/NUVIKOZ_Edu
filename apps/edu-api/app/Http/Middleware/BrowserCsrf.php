<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

class BrowserCsrf extends PreventRequestForgery
{
    // Exercise the real token check in tests, even with same-origin fetch metadata.
    protected function runningUnitTests()
    {
        return false;
    }

    protected function hasValidOrigin($request)
    {
        return false;
    }
}
