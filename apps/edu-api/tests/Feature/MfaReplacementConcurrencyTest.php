<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Services\MfaReplacement;
use App\Services\PlatformFirstFactor;
use App\Services\Totp;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MfaReplacementConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public static function races(): array
    {
        $cases = [];
        foreach (['school', 'platform'] as $context) {
            foreach (['start', 'confirm', 'password', 'inactive'] as $operation) {
                $cases[$context.'-'.$operation] = [$context, $operation];
            }
        }

        return $cases;
    }

    #[DataProvider('races')]
    public function test_independent_replacement_races_preserve_atomic_security_state(string $context, string $operation): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Real independent-process MariaDB race requires isolated CI.');
        }
        $this->assertSame('edu_test', config('database.connections.mysql.database'));
        config(['platform.synthetic_auth' => true]);
        $password = 'Synthetic-Replacement-Password';
        $user = User::create(['name' => 'Synthetic replacement race', 'password' => $password]);
        $session = app('session')->driver();
        $session->start();
        request()->setLaravelSession($session);
        if ($context === 'platform') {
            $user->forceFill(['is_platform_admin' => true])->save();
            app(PlatformFirstFactor::class)->execute(request(), ['identifier' => $user->id, 'password' => $password]);
        } else {
            $school = School::create(['code' => 'RACE', 'name' => 'Synthetic', 'data_stage' => 'live', 'access_state' => 'live_enabled', 'entitlement_ends_at' => now()->addDay()]);
            $member = Membership::create(['user_id' => $user->id, 'school_id' => $school->id, 'role' => 'school_admin', 'login_identifier' => 'RACE', 'status' => 'active']);
            $this->backupAdminFixture($member);
            Auth::guard('web')->login($user);
            $session->put(['school_id' => $school->id, 'credential_hash' => hash('sha256', $user->password), 'issued_at' => now()->timestamp, 'seen_at' => now()->timestamp]);
        }
        $version = (string) Str::ulid();
        DB::table('mfa_credentials')->insert(['user_id' => $user->id, 'secret_encrypted' => Crypt::encryptString(app(Totp::class)->seed()), 'version' => $version, 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $recovery = bin2hex(random_bytes(16));
        DB::table('mfa_recovery_codes')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->id, 'code_hash' => hash('sha256', $recovery), 'created_at' => now(), 'updated_at' => now()]);
        $data = ['user' => $user->id, 'password' => $password, 'context' => $context, 'code' => $recovery];
        if ($operation !== 'start') {
            $pending = app(MfaReplacement::class)->start(request(), $context, $password, $recovery, 'recovery');
            $data['replacement_id'] = $pending['replacement_id'];
            $data['code'] = app(Totp::class)->code($pending['secret'], now()->timestamp);
        }
        $data['session_id'] = $session->getId();
        $data['session'] = $session->all();
        $folder = sys_get_temp_dir().'/edu-replacement-'.bin2hex(random_bytes(8));
        mkdir($folder, 0700);
        $jobs = [];
        $env = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'edu_test', 'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => (string) config('database.connections.mysql.port'), 'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password'), 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'BCRYPT_ROUNDS' => '4'];
        try {
            foreach ([0, 1] as $n) {
                $op = $operation === 'start' ? 'start' : ($n === 0 ? 'confirm' : $operation);
                $job = new Process([PHP_BINARY, base_path('../../scripts/mfa-replacement-race-worker.php')], base_path(), $env, json_encode($data + ['operation' => $op, 'ready' => $folder.'/ready'.$n, 'gate' => $folder.'/go']), 20);
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
                $this->assertSame(0, $job->wait(), 'Independent replacement worker failed');
                $results[] = trim($job->getOutput());
            }
            $table = $context === 'platform' ? 'platform_security_audits' : 'audit_events';
            $prefix = $context === 'platform' ? 'platform.' : '';
            if (in_array($operation, ['start', 'confirm'], true)) {
                sort($results);
                $this->assertSame(['changed', 'denied'], $results);
                $this->assertSame(1, DB::table($table)->where('action', $prefix.($operation === 'start' ? 'mfa.replacement_started' : 'mfa.replaced'))->count());
            } else {
                $this->assertSame('updated', $results[1]);
                $this->assertContains($results[0], ['changed', 'denied']);
                $this->assertSame($results[0] === 'changed' ? 1 : 0, DB::table($table)->where('action', $prefix.'mfa.replaced')->count());
            }
            $changed = $operation !== 'start' && in_array('changed', $results, true);
            $newVersion = DB::table('mfa_credentials')->where('user_id', $user->id)->value('version');
            $this->assertSame(! $changed, $version === $newVersion);
            $this->assertNotNull(DB::table('mfa_credentials')->where('user_id', $user->id)->value('confirmed_at'));
            $this->assertSame($changed ? 0 : 1, DB::table('mfa_replacements')->where('user_id', $user->id)->count());
            $this->assertSame($changed ? 8 : 1, DB::table('mfa_recovery_codes')->where('user_id', $user->id)->count());
            if (! $changed) {
                $this->assertNotNull(DB::table('mfa_recovery_codes')->where('user_id', $user->id)->value('consumed_at'));
            }
        } finally {
            foreach ($jobs as $job) {
                if ($job->isRunning()) {
                    $job->stop();
                }
            }
            foreach (glob($folder.'/*') as $file) {
                unlink($file);
            }
            rmdir($folder);
        }
    }
}
