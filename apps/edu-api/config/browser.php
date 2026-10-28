<?php

return [
    // Explicit Edu-only, same-site HTTPS origins. Empty is fail-closed.
    'origins' => array_values(array_filter(explode(',', env('EDU_BROWSER_ORIGINS', '')))),
    'secure' => env('EDU_BROWSER_SECURE', true),
    'allow_local_http' => env('EDU_BROWSER_ALLOW_LOCAL_HTTP', false),
    'trusted_proxies' => array_values(array_filter(explode(',', env('EDU_BROWSER_TRUSTED_PROXIES', '')))),
];
