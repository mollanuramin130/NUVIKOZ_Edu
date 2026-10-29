<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LastAdminConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_independent_admin_removals_cannot_leave_zero_eligible_administrators(): void
    {
        $this->race(['admin-demote', 'admin-demote']);
    }

    public function test_independent_identity_deactivations_cannot_remove_both_administrators(): void
    {
        $this->race(['admin-deactivate', 'admin-deactivate']);
    }

    public function test_identity_deactivation_racing_membership_demotion_preserves_an_admin(): void
    {
        $this->race(['admin-deactivate', 'admin-demote']);
    }

    private function race(array $operations): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Independent MariaDB last-admin race runs in isolated CI.');
        }
        $this->assertSame('edu_test', config('database.connections.mysql.database'));
        $school = School::create(['code' => 'ADMIN_RACE', 'name' => 'Synthetic', 'data_stage' => 'live', 'access_state' => 'live_enabled', 'entitlement_ends_at' => now()->addDay()]);
        $members = [];
        foreach ([0, 1] as $i) {
            $u = User::create(['name' => 'Synthetic admin', 'password' => 'Synthetic-Race-Old-Password']);
            $members[] = Membership::create(['school_id' => $school->id, 'user_id' => $u->id, 'role' => 'school_admin', 'status' => 'active', 'login_identifier' => 'ADMIN'.$i]);
        }
        $dir = sys_get_temp_dir().'/edu-admin-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $jobs = [];
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'edu_test', 'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => (string) config('database.connections.mysql.port'), 'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password'), 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array'];
        try {
            foreach ($members as $i => $m) {
                $job = new Process([PHP_BINARY, base_path('../../scripts/lifecycle-race-worker.php')], base_path(), $env, json_encode(['operation' => $operations[$i], 'membership' => $m->id, 'session' => str_repeat((string) ($i + 1), 40), 'ready' => $dir.'/ready'.$i, 'gate' => $dir.'/go']), 25);
                $job->start();
                $jobs[] = $job;
            }
            $deadline = microtime(true) + 15;
            while (count(glob($dir.'/ready*')) < 2 && microtime(true) < $deadline) {
                usleep(10000);
            }$this->assertCount(2, glob($dir.'/ready*'));
            file_put_contents($dir.'/go', 'go');
            $result = [];
            foreach ($jobs as $job) {
                $this->assertSame(0, $job->wait(), 'Synthetic admin worker failed');
                $result[] = trim($job->getOutput());
            }sort($result);
            $this->assertSame(['changed', 'denied'], $result);
            $this->assertSame(1, DB::table('memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.school_id', $school->id)->where('m.role', 'school_admin')->where('m.status', 'active')->where('u.status', 'active')->where('u.activation_required', false)->where('u.must_change_password', false)->where('u.is_platform_admin', false)->count());
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
