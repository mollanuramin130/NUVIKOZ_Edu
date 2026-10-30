<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Services\AcademicCatalog;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AcademicOfferingConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public static function races(): array
    {
        $cases = [];
        foreach (['class-offerings', 'sections', 'subjects', 'subject-offerings'] as $kind) {
            $cases[$kind.' duplicate'] = [$kind, false, false];
            $cases[$kind.' replay'] = [$kind, true, false];
        }
        $cases['Demo section cap with distinct names'] = ['sections', false, true];

        return $cases;
    }

    private function fixture(string $kind): array
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Real independent MariaDB processes required; isolated CI executes these.');
        }
        $this->assertSame('edu_test', config('database.connections.mysql.database'));
        $school = School::create(['code' => 'OFFER_RACE', 'name' => 'Synthetic', 'data_stage' => 'live', 'access_state' => 'live_enabled', 'entitlement_ends_at' => now()->addDay(), 'settings' => ['timezone' => 'Asia/Kolkata']]);
        $user = User::create(['name' => 'Synthetic', 'password' => 'Synthetic-Academic-Password']);
        $member = Membership::create(['school_id' => $school->id, 'user_id' => $user->id, 'role' => 'school_admin', 'status' => 'active', 'login_identifier' => 'ADMIN']);
        $this->mfaFixture($member);
        $create = fn ($kind, $data) => app(AcademicCatalog::class)->create($member, $kind, $data, (string) Str::ulid(), 'synthetic-race-setup')['resource'];
        if ($kind === 'subjects') {
            return [$member, ['code' => 'MATH', 'name' => 'Synthetic mathematics']];
        }
        $session = $create('sessions', ['name' => 'Synthetic year', 'starts_on' => '2035-04-01', 'ends_on' => '2036-03-31']);
        $class = $create('classes', ['name' => 'Class 12', 'sort_order' => 12]);
        $data = ['session_id' => $session['id'], 'class_id' => $class['id']];
        if ($kind === 'class-offerings') {
            return [$member, $data];
        }
        $offering = $create('class-offerings', $data);
        if ($kind === 'sections') {
            return [$member, ['class_offering_id' => $offering['id'], 'name' => 'Section A']];
        }
        $subject = $create('subjects', ['code' => 'MATH', 'name' => 'Synthetic mathematics']);

        return [$member, ['class_offering_id' => $offering['id'], 'subject_id' => $subject['id'], 'compulsory' => true]];
    }

    private function worker(array $input, array $extraEnv = []): Process
    {
        $env = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'edu_test', 'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => (string) config('database.connections.mysql.port'), 'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password'), 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array'];

        return new Process([PHP_BINARY, base_path('../../scripts/academic-race-worker.php')], base_path(), $extraEnv + $env, json_encode($input), 30);
    }

    private function waitFor(callable $ready): void
    {
        $deadline = microtime(true) + 15;
        while (! $ready() && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertTrue($ready(), 'Independent process did not reach the required barrier.');
    }

    private function cleanup(string $dir, array $jobs): void
    {
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

    #[DataProvider('races')]
    public function test_independent_creates_preserve_domain_uniqueness_and_receipts(string $kind, bool $replay, bool $demo): void
    {
        [$member, $data] = $this->fixture($kind);
        $table = str_replace('-', '_', $kind);
        if ($demo) {
            $member->school->update(['data_stage' => 'demo', 'access_state' => 'provisional', 'demo_ends_at' => now()->addDay()]);
        }
        $dir = sys_get_temp_dir().'/edu-offering-race-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $jobs = [];
        $counts = [DB::table($table)->count(), DB::table('audit_events')->count(), DB::table('academic_command_receipts')->count()];
        $key = (string) Str::ulid();
        try {
            foreach ([0, 1] as $i) {
                $body = $data;
                if ($demo) {
                    $body['name'] = 'Different section '.$i;
                }
                // A test-only config value is loaded by the guarded CLI worker below.
                $job = $this->worker(['membership' => $member->id, 'kind' => $kind, 'data' => $body, 'key' => $replay ? $key : (string) Str::ulid(), 'demo_limit' => $demo ? 1 : null, 'ready' => $dir.'/ready'.$i, 'gate' => $dir.'/go']);
                $job->start();
                $jobs[] = $job;
            }
            $this->waitFor(fn () => count(glob($dir.'/ready*')) === 2);
            file_put_contents($dir.'/go', 'go');
            $results = [];
            foreach ($jobs as $job) {
                $this->assertSame(0, $job->wait(), $job->getErrorOutput());
                $results[] = trim($job->getOutput());
            }
            sort($results);
            $this->assertSame($replay ? ['created', 'replayed'] : ['created', 'denied'], $results);
            $this->assertSame($counts[0] + 1, DB::table($table)->count());
            $this->assertSame($counts[1] + 1, DB::table('audit_events')->count());
            $this->assertSame($counts[2] + 1, DB::table('academic_command_receipts')->count());
        } finally {
            $this->cleanup($dir, $jobs);
        }
    }

    public function test_membership_revocation_while_command_waits_on_school_lock_denies_all_mutation(): void
    {
        [$member, $data] = $this->fixture('subjects');
        $this->backupAdminFixture($member);
        $dir = sys_get_temp_dir().'/edu-offering-revoke-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $job = $this->worker(['membership' => $member->id, 'kind' => 'subjects', 'data' => $data, 'key' => (string) Str::ulid(), 'ready' => $dir.'/ready', 'gate' => $dir.'/go', 'lock_attempt' => $dir.'/lock']);
        try {
            DB::beginTransaction();
            DB::table('schools')->where('id', $member->school_id)->lockForUpdate()->first();
            $job->start();
            $this->waitFor(fn () => is_file($dir.'/ready'));
            file_put_contents($dir.'/go', 'go');
            $this->waitFor(fn () => is_file($dir.'/lock'));
            $member->fresh()->update(['status' => 'inactive']);
            DB::commit();
            $this->assertSame(0, $job->wait(), $job->getErrorOutput());
            $this->assertSame('denied', trim($job->getOutput()));
            $this->assertDatabaseCount('subjects', 0);
            $this->assertDatabaseCount('academic_command_receipts', 0);
            $this->assertDatabaseCount('audit_events', 0);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->cleanup($dir, [$job]);
        }
    }
}
