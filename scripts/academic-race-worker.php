<?php

use App\Models\Membership;
use App\Services\AcademicCatalog;
use App\Services\PrivilegedMfa;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

require __DIR__.'/../apps/edu-api/vendor/autoload.php';
$app = require __DIR__.'/../apps/edu-api/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'edu_test') {
    exit(3);
}
$in = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$m = Membership::with(['user', 'school'])->findOrFail($in['membership']);
$session = app('session')->driver();
$session->start();
request()->setLaravelSession($session);
Auth::guard('web')->login($m->user);
$session->put(['school_id' => $m->school_id, 'credential_hash' => hash('sha256', $m->user->password), 'issued_at' => time(), 'seen_at' => time()]);
$session->put('mfa_assurance', ['version' => DB::table('mfa_credentials')->where('user_id', $m->user_id)->value('version'), 'session' => hash('sha256', $session->getId()), 'binding' => app(PrivilegedMfa::class)->binding($m), 'at' => time()]);
file_put_contents($in['ready'], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($in['gate'])) {
    if (microtime(true) > $deadline) {
        exit(4);
    }
    usleep(10000);
}
try {
    $result = app(AcademicCatalog::class)->create($m, $in['kind'], $in['data'], $in['key'], 'synthetic-academic-race');
    echo $result['replayed'] ? 'replayed' : 'created';
} catch (HttpException $e) {
    if ($e->getStatusCode() !== 409) {
        throw $e;
    }
    echo 'denied';
}
