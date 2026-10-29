<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlatformFirstFactor;
use App\Services\PlatformMfa;
use App\Services\Totp;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PlatformMfaConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_independent_recovery_code_consumers_have_one_winner(): void
    {
        $this->race('recovery');
    }

    public function test_independent_totp_consumers_have_one_winner(): void
    {
        $this->race('totp');
    }

    private function race(string $mode): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Independent MariaDB race runs in isolated CI.');
        }
        $this->assertSame('edu_test', config('database.connections.mysql.database'));
        config(['platform.synthetic_auth' => true]);
        $user = User::create(['name' => 'Synthetic platform', 'password' => 'Synthetic-Admin-Password']);
        $user->forceFill(['is_platform_admin' => true])->save();
        $session = app('session')->driver();
        $session->start();
        request()->setLaravelSession($session);
        app(PlatformFirstFactor::class)->execute(request(), ['identifier' => $user->id, 'password' => 'Synthetic-Admin-Password']);
        $setup = app(PlatformMfa::class)->enroll(request(), 'Synthetic-Admin-Password');
        $result = app(PlatformMfa::class)->verify(request(), 'Synthetic-Admin-Password', app(Totp::class)->code($setup['secret'], now()->timestamp), 'confirm');
        $data = ['user' => $user->id, 'password' => 'Synthetic-Admin-Password', 'mode' => $mode, 'code' => $mode === 'recovery' ? $result['recovery_codes'][0] : app(Totp::class)->code($setup['secret'], (intdiv(now()->timestamp, 30) + 1) * 30)];
        $folder = sys_get_temp_dir().'/edu-mfa-'.bin2hex(random_bytes(8));
        mkdir($folder, 0700);
        $jobs = [];
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'edu_test', 'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => (string) config('database.connections.mysql.port'), 'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password'), 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array'];
        try {
            foreach ([0, 1] as $n) {
                $job = new Process([PHP_BINARY, base_path('../../scripts/platform-mfa-race-worker.php')], base_path(), $env, json_encode(array_merge($data, ['ready' => $folder.'/ready'.$n, 'gate' => $folder.'/go'])), 20);
                $job->start();
                $jobs[] = $job;
            }
            $deadline = microtime(true) + 10;
            while (count(glob($folder.'/ready*')) < 2 && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertCount(2, glob($folder.'/ready*'));
            file_put_contents($folder.'/go', 'go');
            $results = [];
            foreach ($jobs as $job) {
                $this->assertSame(0, $job->wait(), 'Race worker failed');
                $results[] = trim($job->getOutput());
            }sort($results);
            $this->assertSame(['rejected', 'verified'], $results);
            $this->assertSame(1, DB::table('platform_security_audits')->where('action', 'platform.mfa_'.$mode)->count());
            if ($mode === 'recovery') {
                $this->assertSame(1, DB::table('mfa_recovery_codes')->where('user_id', $user->id)->whereNotNull('consumed_at')->count());
            }
            $this->assertNotNull(DB::table('mfa_credentials')->where('user_id', $user->id)->value('confirmed_at'));
        } finally {
            foreach ($jobs as $job) {
                if ($job->isRunning()) {
                    $job->stop();
                }
            }foreach (glob($folder.'/*') as $file) {
                unlink($file);
            }rmdir($folder);
        }
    }
}
