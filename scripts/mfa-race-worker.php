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
$m = Membership::with(['user', 'school'])->findOrFail($input['membership']);
$session = app('session')->driver(); $session->start(); request()->setLaravelSession($session);
Auth::guard('web')->login($m->user);
$session->put(['school_id' => $m->school_id, 'credential_hash' => hash('sha256', $m->user->password), 'issued_at' => now()->timestamp, 'seen_at' => now()->timestamp, 'mfa_challenge_expires_at' => now()->addMinutes(5)->timestamp]);
file_put_contents($input['ready'], 'ready'); $deadline = microtime(true) + 10;
while (! is_file($input['gate'])) { if (microtime(true) > $deadline) { exit(4); } usleep(10000); }
try { app(PrivilegedMfa::class)->verify($m, request(), $input['password'], $input['code'], $input['mode']); echo 'verified'; }
catch (ValidationException) { echo 'rejected'; }
