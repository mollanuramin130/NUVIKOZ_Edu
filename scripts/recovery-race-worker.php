<?php

use App\Services\AccountRecovery;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

// Isolated MariaDB race worker. Proof/password arrive only through stdin, never command lines/logs.
require __DIR__.'/../apps/edu-api/vendor/autoload.php';
$app = require __DIR__.'/../apps/edu-api/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'edu_test') {
    exit(3);
}
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
file_put_contents($input['ready'], 'ready');
$deadline = microtime(true) + 10;
while (! is_file($input['gate'])) {
    if (microtime(true) > $deadline) {
        exit(4);
    }usleep(10000);
}
try {
    app(AccountRecovery::class)->consume($input['data'], 'synthetic-race');
    echo 'reset';
} catch (ValidationException) {
    echo 'rejected';
}
