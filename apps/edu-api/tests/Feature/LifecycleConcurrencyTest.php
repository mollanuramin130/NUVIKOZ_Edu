<?php

namespace Tests\Feature;

use App\Http\Middleware\BrowserSession;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Services\SecurityInventory;
use App\Support\Api;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LifecycleConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_login_racing_password_rotation_never_leaves_an_old_credential_token(): void
    {
        $this->race('login', 'password');
    }

    public function test_login_racing_membership_revocation_cannot_leave_authorized_device(): void
    {
        $this->race('login', 'membership');
    }

    public function test_concurrent_device_revocations_have_one_winner(): void
    {
        $this->race('device-revoke', 'device-revoke');
    }

    public function test_revocation_defeats_a_concurrent_protected_session_late_write(): void
    {
        $this->race('protected-session', 'session-revoke');
    }

    private function race(string $first, string $second): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Independent MariaDB-process acceptance runs in isolated CI.');
        }
        $this->assertSame('edu_test', config('database.connections.mysql.database'));
        $s = School::create(['code' => 'RACE', 'name' => 'Synthetic', 'data_stage' => 'live', 'access_state' => 'live_enabled', 'entitlement_ends_at' => now()->addDay()]);
        $u = User::create(['name' => 'Synthetic', 'password' => 'Synthetic-Race-Old-Password']);
        $m = Membership::create(['school_id' => $s->id, 'user_id' => $u->id, 'role' => 'teacher', 'status' => 'active', 'login_identifier' => 'RACE']);
        $token = $u->createToken('Synthetic', ['school:'.$s->id], now()->addDays(7));
        $oldSession = str_repeat('a', 40);
        DB::table('sessions')->insert(['id' => $oldSession, 'user_id' => $u->id, 'payload' => 'synthetic', 'last_activity' => time()]);
        $probe = function () use ($u, $s, $oldSession) {
            $session = new Store('synthetic', new ArraySessionHandler(480));
            $session->setId($oldSession);
            $session->start();
            request()->setLaravelSession($session);
            Auth::guard('web')->setUser($u->fresh());
            $session->put(['school_id' => $s->id, 'credential_hash' => hash('sha256', $u->fresh()->password), 'issued_at' => time(), 'seen_at' => time()]);

            return app(BrowserSession::class)->handle(request(), fn () => Api::ok([]))->getStatusCode();
        };
        if ($second === 'session-revoke') {
            $this->assertSame(200, $probe(), 'The same session must be eligible before revocation.');
        }
        $target = app(SecurityInventory::class)->opaque(str_contains($first, 'device') ? 'device' : 'session', str_contains($first, 'device') ? (string) $token->accessToken->id : $oldSession, $u->id);
        $dir = sys_get_temp_dir().'/edu-lifecycle-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $jobs = [];
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'edu_test', 'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => (string) config('database.connections.mysql.port'), 'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password'), 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array'];
        try {
            foreach ([$first, $second] as $i => $op) {
                $input = ['operation' => $op, 'membership' => $m->id, 'target' => $target, 'session' => $op === 'protected-session' ? $oldSession : str_repeat((string) ($i + 1), 40), 'gate' => $dir.'/go', 'ready' => $dir.'/ready'.$i, 'done' => $dir.'/done'];
                $job = new Process([PHP_BINARY, base_path('../../scripts/lifecycle-race-worker.php')], base_path(), $env, json_encode($input), 25);
                $job->start();
                $jobs[] = $job;
            }
            $deadline = microtime(true) + 15;
            while (count(glob($dir.'/ready*')) < 2 && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertCount(2, glob($dir.'/ready*'));
            file_put_contents($dir.'/go', 'go');
            $results = [];
            foreach ($jobs as $job) {
                $this->assertSame(0, $job->wait(), 'Synthetic lifecycle worker failed');
                $results[] = trim($job->getOutput());
            }
            if ($second === 'password') {
                $this->assertTrue(Hash::check('Synthetic-Race-New-Password', $u->fresh()->password));
                $this->assertSame(0, $u->tokens()->count());
            }
            if ($second === 'membership') {
                $this->assertSame('inactive', $m->fresh()->status);
                $this->assertSame(0, $u->tokens()->count());
            }
            if ($first === 'device-revoke') {
                sort($results);
                $this->assertSame(['denied', 'revoked'], $results);
                $this->assertSame(1, DB::table('audit_events')->where('action', 'security.device_revoked')->count());
            }
            if ($second === 'session-revoke') {
                $this->assertDatabaseHas('session_revocations', ['session_hash' => hash('sha256', $oldSession)]);
                $this->assertDatabaseHas('sessions', ['id' => $oldSession, 'payload' => 'synthetic-late-writer']);
                $this->assertSame('revoked', $results[1]);
                $this->assertSame(401, $probe());
            }
        } finally {
            foreach ($jobs as $job) {
                if ($job->isRunning()) {
                    $job->stop();
                }
            }foreach (glob($dir.'/*') as $file) {
                unlink($file);
            }rmdir($dir);
        }
    }
}
