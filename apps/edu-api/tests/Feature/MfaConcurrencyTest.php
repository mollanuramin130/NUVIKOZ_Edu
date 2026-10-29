<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Services\PrivilegedMfa;
use App\Services\Totp;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MfaConcurrencyTest extends TestCase
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
        $school = School::create(['code' => 'RACE', 'name' => 'Synthetic race', 'data_stage' => 'live', 'access_state' => 'live_enabled', 'entitlement_ends_at' => now()->addDay()]);
        $user = User::create(['name' => 'Synthetic admin', 'password' => 'Synthetic-Admin-Password']);
        $admin = Membership::create(['school_id' => $school->id, 'user_id' => $user->id, 'role' => 'school_admin', 'login_identifier' => 'ADMIN', 'status' => 'active']);
        $session = app('session')->driver();
        $session->start();
        request()->setLaravelSession($session);
        Auth::guard('web')->login($admin->user);
        $session->put(['school_id' => $admin->school_id, 'credential_hash' => hash('sha256', $admin->user->password), 'issued_at' => now()->timestamp, 'seen_at' => now()->timestamp, 'mfa_challenge_expires_at' => now()->addMinutes(5)->timestamp]);
        Cache::flush();
        $targetUser = User::create(['name' => 'Synthetic subject', 'password' => 'Synthetic-Original-Password']);
        $target = Membership::create(['school_id' => $school->id, 'user_id' => $targetUser->id, 'role' => 'teacher', 'login_identifier' => 'SUBJECT', 'status' => 'active']);
        $setup = app(PrivilegedMfa::class)->enroll($admin, request(), 'Synthetic-Admin-Password');
        $result = app(PrivilegedMfa::class)->verify($admin, request(), 'Synthetic-Admin-Password', app(Totp::class)->code($setup['secret'], now()->timestamp), 'confirm');
        $data = ['membership' => $admin->id, 'password' => 'Synthetic-Admin-Password', 'mode' => $mode, 'code' => $mode === 'recovery' ? $result['recovery_codes'][0] : app(Totp::class)->code($setup['secret'], (intdiv(now()->timestamp, 30) + 1) * 30)];
        $folder = sys_get_temp_dir().'/edu-mfa-'.bin2hex(random_bytes(8));
        mkdir($folder, 0700);
        $jobs = [];
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'edu_test', 'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => (string) config('database.connections.mysql.port'), 'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password'), 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array'];
        try {
            foreach ([0, 1] as $n) {
                $job = new Process([PHP_BINARY, base_path('../../scripts/mfa-race-worker.php')], base_path(), $env, json_encode(array_merge($data, ['ready' => $folder.'/ready'.$n, 'gate' => $folder.'/go'])), 20);
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
            $this->assertSame(1, DB::table('audit_events')->where('action', 'mfa.verified')->count());
            $this->assertNotNull(DB::table('mfa_credentials')->where('user_id', $admin->user_id)->value('confirmed_at'));
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
