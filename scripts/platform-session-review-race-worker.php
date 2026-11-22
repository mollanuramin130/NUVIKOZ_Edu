<?php

use App\Models\User;
use App\Services\AcademicSessionChanges;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../apps/edu-api/vendor/autoload.php';
$app = require __DIR__.'/../apps/edu-api/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'edu_test') {
    exit(3);
}
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
config(['platform.synthetic_auth' => true]);
$session = app('session')->driver();
$session->setId($input['session_id']);
$session->start();
$session->put($input['session']);
$request = request();
$request->setLaravelSession($session);
$request->setUserResolver(fn () => User::findOrFail($input['actor']));
$request->attributes->set('request_id', 'synthetic-platform-review-race');

file_put_contents($input['ready'], 'ready');
$deadline = microtime(true) + 10;
while (! is_file($input['gate'])) {
    if (microtime(true) > $deadline) {
        exit(4);
    }
    usleep(10000);
}
try {
    $result = app(AcademicSessionChanges::class)->review($request, $input['id'], $input['body'], $input['key']);
    echo $result['state'];
} catch (HttpException $e) {
    if ($e->getStatusCode() !== 409) {
        throw $e;
    }
    echo 'conflict';
}
