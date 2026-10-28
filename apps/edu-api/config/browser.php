<?php

return [
    // Explicit Edu-only, same-site HTTPS origins. Empty is fail-closed.
    'origins' => array_values(array_filter(explode(',', env('EDU_BROWSER_ORIGINS', '')))),
    'secure' => env('EDU_BROWSER_SECURE', true),
];
