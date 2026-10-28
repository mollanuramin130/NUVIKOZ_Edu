<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

class EduTrustProxies extends TrustProxies
{
    protected function setTrustedProxyIpAddresses(Request $request)
    {
        // Explicit IP addresses only; never trust arbitrary hosts, wildcards or headers.
        $proxies = array_values(array_filter(config('browser.trusted_proxies', []), fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP) !== false));
        $request::setTrustedProxies($proxies, Request::HEADER_X_FORWARDED_PROTO);
    }
}
