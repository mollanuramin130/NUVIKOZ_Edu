<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Services\AccountRecovery;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RecoveryConcurrencyTest extends TestCase
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
        Cache::flush();
        $targetUser = User::create(['name' => 'Synthetic subject', 'password' => 'Synthetic-Original-Password']);
        $target = Membership::create(['school_id' => $school->id, 'user_id' => $targetUser->id, 'role' => 'teacher', 'login_identifier' => 'SUBJECT', 'status' => 'active']);
        $proof = app(AccountRecovery::class)->issue($admin, $target->id, ['verification_method' => 'in_person_registered_record', 'verification_reference' => 'synthetic-race-case-0001', 'identity_verified' => true], 'race-issue');
        $data = ['school_code' => 'RACE', 'proof_id' => $proof['proof_id'], 'secret' => $proof['secret'], 'password' => 'Synthetic-Race-Password', 'password_confirmation' => 'Synthetic-Race-Password'];
        $folder = sys_get_temp_dir().'/edu-recovery-'.bin2hex(random_bytes(8));
        mkdir($folder, 0700);
        $jobs = [];
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'edu_test', 'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => (string) config('database.connections.mysql.port'), 'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password')];
        try {
            foreach ([0, 1] as $n) {
                $job = new Process([PHP_BINARY, base_path('../../scripts/recovery-race-worker.php')], base_path(), $env, json_encode(['data' => $data, 'ready' => $folder.'/ready'.$n, 'gate' => $folder.'/go']), 20);
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
            $this->assertSame(['rejected', 'reset'], $results);
            $this->assertSame(1, DB::table('audit_events')->where('action', 'recovery.completed')->count());
            $this->assertNotNull(DB::table('account_recoveries')->where('id', $proof['proof_id'])->value('consumed_at'));
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
