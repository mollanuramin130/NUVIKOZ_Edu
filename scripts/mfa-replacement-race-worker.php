<?php

use App\Models\User;
use App\Services\MfaReplacement;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../apps/edu-api/vendor/autoload.php';
$app = require __DIR__.'/../apps/edu-api/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (PHP_SAPI !== 'cli' || ! app()->environment('testing') || config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'edu_test') {
    exit(3);
}
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
config(['platform.synthetic_auth' => true]);
$session = app('session')->driver();
$session->start();
$session->setId($input['session_id']);
$session->replace($input['session']);
request()->setLaravelSession($session);
file_put_contents($input['ready'], 'ready');
$deadline = microtime(true) + 10;
while (! is_file($input['gate'])) {
    if (microtime(true) > $deadline) {
        exit(4);
    } usleep(10000);
}
try {
    if ($input['operation'] === 'password' || $input['operation'] === 'inactive') {
        User::findOrFail($input['user'])->update($input['operation'] === 'password' ? ['password' => 'Changed-Synthetic-Race-Password'] : ['status' => 'inactive']);
        echo 'updated';
    } else {
        $service = app(MfaReplacement::class);
        if ($input['operation'] === 'start') {
            $service->start(request(), $input['context'], $input['password'], $input['code'], 'recovery');
        } else {
            $service->confirm(request(), $input['context'], $input['password'], $input['replacement_id'], $input['code']);
        }
        echo 'changed';
    }
} catch (HttpException $e) {
    if (! in_array($e->getStatusCode(), [403, 409, 422], true)) {
        exit(5);
    }
    echo 'denied';
}
