<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AcademicConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_independent_identical_commands_create_once_and_replay(): void
    {
        $this->race('sessions', true, ['created', 'replayed']);
    }

    public function test_independent_overlapping_future_sessions_have_one_winner(): void
    {
        $this->race('sessions', false, ['created', 'denied']);
    }

    public function test_independent_duplicate_classes_have_one_winner(): void
    {
        $this->race('classes', false, ['created', 'denied']);
    }

    private function race(string $kind, bool $replay, array $expected): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Requires independent processes and disposable MariaDB in CI.');
        }
        $this->assertSame('edu_test', config('database.connections.mysql.database'));
        $school = School::create(['code' => 'ACA_RACE', 'name' => 'Synthetic', 'data_stage' => 'live', 'access_state' => 'live_enabled', 'entitlement_ends_at' => now()->addDay(), 'settings' => ['timezone' => 'Asia/Kolkata']]);
        $user = User::create(['name' => 'Synthetic', 'password' => 'Synthetic-Academic-Password']);
        $member = Membership::create(['school_id' => $school->id, 'user_id' => $user->id, 'role' => 'school_admin', 'status' => 'active', 'login_identifier' => 'ADMIN']);
        $this->mfaFixture($member);
        $dir = sys_get_temp_dir().'/edu-academic-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $jobs = [];
        $env = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'edu_test', 'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => (string) config('database.connections.mysql.port'), 'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password'), 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array'];
        $key = (string) Str::ulid();
        try {
            foreach ([0, 1] as $i) {
                $data = $kind === 'sessions' ? ['name' => 'Synthetic year '.($replay ? 0 : $i), 'starts_on' => '2035-04-01', 'ends_on' => '2036-03-31'] : ['name' => 'Synthetic class', 'sort_order' => 1];
                $job = new Process([PHP_BINARY, base_path('../../scripts/academic-race-worker.php')], base_path(), $env, json_encode(['membership' => $member->id, 'kind' => $kind, 'data' => $data, 'key' => $replay ? $key : (string) Str::ulid(), 'ready' => $dir.'/ready'.$i, 'gate' => $dir.'/go']), 25);
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
            sort($results);
            $this->assertSame($expected, $results);
            $this->assertSame(1, DB::table($kind === 'sessions' ? 'academic_sessions' : 'classes')->count());
            $this->assertSame(1, DB::table('academic_command_receipts')->count());
            $this->assertSame(1, DB::table('audit_events')->where('action', 'academic.'.$kind.'.created')->count());
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
