<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StaffProfileConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Separate-process staff race requires disposable MariaDB.');
        }
        $this->assertSame('edu_test', config('database.connections.mysql.database'));
        $this->refreshTestDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            $this->artisan('migrate:fresh')->assertSuccessful();
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_exact_retry_has_one_audit_and_receipt(): void
    {
        $this->race(true, ['created', 'replayed']);
    }

    public function test_different_keys_for_same_target_have_one_winner(): void
    {
        $this->race(false, ['STAFF_PROFILE_EXISTS', 'created']);
    }

    private function race(bool $sameKey, array $expected): void
    {
        $school = School::create(['code' => 'STAFF_RACE', 'name' => 'Synthetic staff school',
            'data_stage' => 'live', 'access_state' => 'live_enabled', 'entitlement_ends_at' => now()->addDay()]);
        $members = [];
        foreach (['school_admin', 'teacher'] as $role) {
            $u = User::create(['name' => 'Synthetic staff', 'password' => 'Synthetic-Staff-Password']);
            $members[$role] = Membership::create(['school_id' => $school->id, 'user_id' => $u->id,
                'role' => $role, 'status' => 'active', 'login_identifier' => $role]);
        }
        $actor = $members['school_admin'];
        $this->mfaFixture($actor);
        $dir = sys_get_temp_dir().'/edu-staff-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $jobs = [];
        $key = (string) Str::ulid();
        $env = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => 'edu_test', 'DB_HOST' => config('database.connections.mysql.host'),
            'DB_PORT' => (string) config('database.connections.mysql.port'), 'DB_USERNAME' => config('database.connections.mysql.username'),
            'DB_PASSWORD' => config('database.connections.mysql.password'), 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array'];
        try {
            foreach ([0, 1] as $i) {
                $job = new Process([PHP_BINARY, base_path('../../scripts/staff-profile-race-worker.php')], base_path(), $env,
                    json_encode(['membership' => $actor->id, 'target' => $members['teacher']->id,
                        'key' => $sameKey ? $key : (string) Str::ulid(), 'ready' => $dir.'/ready'.$i, 'gate' => $dir.'/go'], JSON_THROW_ON_ERROR), 25);
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
                $this->assertSame(0, $job->wait(), $job->getErrorOutput());
                $results[] = trim($job->getOutput());
            }
            $this->assertEqualsCanonicalizing($expected, $results);
            $this->assertDatabaseCount('staff_profiles', 1);
            $this->assertSame(1, DB::table('audit_events')->where('action', 'staff.profile.created')->count());
            $this->assertSame(1, DB::table('idempotency_records')->where('operation', 'staff.profile.register')->count());
        } finally {
            foreach ($jobs as $job) {
                if ($job->isRunning()) {
                    $job->stop();
                }
            }
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }
}
