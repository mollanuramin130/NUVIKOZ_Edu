<?php

// Explicit disposable HTTPS browser harness. Not loaded by public/index.php or Laravel config.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/../apps/edu-api/vendor/autoload.php';
$app = require __DIR__.'/../apps/edu-api/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (PHP_SAPI !== 'cli-server' || ! app()->environment('testing')
    || config('database.connections.'.config('database.default').'.database') !== 'edu_test'
    || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit;
}
config(['platform.synthetic_auth' => true]);
$app->handleRequest(Request::capture());
