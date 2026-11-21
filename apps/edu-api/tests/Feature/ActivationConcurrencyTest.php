<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Services\AccountActivation;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ActivationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_independent_mariadb_consumers_have_exactly_one_winner(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Independent MariaDB race runs in isolated CI.');
        }
        $this->assertSame('edu_test', config('database.connections.mysql.database'));
        $school = School::create(['code' => 'RACE', 'name' => 'Synthetic race', 'data_stage' => 'live', 'access_state' => 'live_enabled', 'entitlement_ends_at' => now()->addDay()]);
        $user = User::create(['name' => 'Synthetic admin', 'password' => 'Synthetic-Admin-Password']);
        $admin = Membership::create(['school_id' => $school->id, 'user_id' => $user->id, 'role' => 'school_admin', 'login_identifier' => 'ADMIN', 'status' => 'active']);
        $this->mfaFixture($admin);
        $result = app(AccountActivation::class)->provision($admin, ['name' => 'Synthetic subject', 'role' => 'teacher', 'login_identifier' => 'SUBJECT'], 'race-provision', 'synthetic-race-key-0001');
        $proof = $result['local_handover'];
        $data = ['school_code' => 'RACE', 'proof_id' => $proof['proof_id'], 'secret' => $proof['secret'], 'password' => 'Synthetic-Race-Password', 'password_confirmation' => 'Synthetic-Race-Password'];
        $this->assertSame(['activated', 'rejected'], $this->race(['data' => $data]));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'activation.completed')->count());
        $this->assertNotNull(DB::table('account_activations')->where('id', $proof['proof_id'])->value('consumed_at'));
    }

    public function test_two_independent_bootstrap_attempts_create_only_one_school_admin(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Independent MariaDB race runs in isolated CI.');
        }
        $this->assertSame('edu_test', config('database.connections.mysql.database'));
        $manifest = json_encode([
            'school_code' => 'EDU-STAGE-RACE',
            'proof_id' => (string) Str::ulid(),
            'secret_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'expires_at' => now()->addMinutes(15)->timestamp,
        ], JSON_THROW_ON_ERROR);

        $this->assertSame(['initialized', 'rejected'], $this->race(['manifest' => $manifest]));
        $this->assertDatabaseCount('schools', 1);
        $this->assertDatabaseCount('memberships', 1);
        $this->assertDatabaseCount('initial_school_admin_proofs', 1);
        $this->assertDatabaseCount('audit_events', 1);
    }

    private function race(array $payload): array
    {
        $folder = sys_get_temp_dir().'/edu-activation-'.bin2hex(random_bytes(8));
        mkdir($folder, 0700);
        $jobs = [];
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'edu_test', 'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => (string) config('database.connections.mysql.port'), 'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password')];
        try {
            foreach ([0, 1] as $n) {
                $job = new Process([PHP_BINARY, base_path('../../scripts/activation-race-worker.php')], base_path(), $env, json_encode($payload + ['ready' => $folder.'/ready'.$n, 'gate' => $folder.'/go'], JSON_THROW_ON_ERROR), 20);
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
            }
            sort($results);

            return $results;
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
