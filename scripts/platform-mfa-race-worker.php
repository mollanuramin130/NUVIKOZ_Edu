<?php

use App\Models\Membership;
use App\Services\PrivilegedMfa;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../apps/edu-api/vendor/autoload.php';
$app = require __DIR__.'/../apps/edu-api/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'edu_test') { exit(3); }
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
config(['platform.synthetic_auth'=>true]);
$session=app('session')->driver();$session->start();request()->setLaravelSession($session);
app(\App\Services\PlatformFirstFactor::class)->execute(request(),['identifier'=>$input['user'],'password'=>$input['password']]);
file_put_contents($input['ready'], 'ready'); $deadline = microtime(true) + 10;
while (! is_file($input['gate'])) { if (microtime(true) > $deadline) { exit(4); } usleep(10000); }
try { app(\App\Services\PlatformMfa::class)->verify(request(), $input['password'], $input['code'], $input['mode']); echo 'verified'; }
catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { if(!in_array($e->getStatusCode(),[403,422],true))exit(5); echo 'rejected'; }
