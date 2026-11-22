<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\PlatformFirstFactor;
use App\Services\PlatformMfa;
use App\Services\PlatformSessionReview;
use App\Services\Totp;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PlatformSessionReviewConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Separate-process review race requires disposable MariaDB.');
        }
        $this->assertSame('edu_test', config('database.connections.mysql.database'));
        $this->refreshTestDatabase();
        $this->beforeApplicationDestroyed(function (): void {
            // The real rollback refuses to drop nonempty append-only access audit details.
            $this->artisan('migrate:fresh')->assertSuccessful();
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_approve_approve_has_one_decision(): void
    {
        $this->race('approve');
    }

    public function test_approve_reject_has_one_decision(): void
    {
        $this->race('reject');
    }

    private function race(string $second): void
    {
        config(['platform.synthetic_auth' => true]);
        $school = School::create(['code' => 'REV_RACE', 'name' => 'Synthetic review school', 'data_stage' => 'live',
            'access_state' => 'live_enabled', 'entitlement_ends_at' => now()->addDay(), 'settings' => ['timezone' => 'Asia/Kolkata']]);
        $requester = User::create(['name' => 'Synthetic requester', 'password' => 'Synthetic-Requester-Password']);
        $sessionId = (string) Str::ulid();
        $id = (string) Str::ulid();
        $starts = now('Asia/Kolkata')->subDay()->toDateString();
        $ends = now('Asia/Kolkata')->addDays(2)->toDateString();
        $proposed = now('Asia/Kolkata')->addDays(5)->toDateString();
        DB::table('academic_sessions')->insert(['id' => $sessionId, 'school_id' => $school->id, 'name' => 'Race term',
            'starts_on' => $starts, 'ends_on' => $ends, 'state' => 'active', 'version' => 1]);
        DB::table('session_change_requests')->insert(['id' => $id, 'school_id' => $school->id, 'session_id' => $sessionId,
            'requester_id' => $requester->id, 'proposed_start' => $starts, 'proposed_end' => $proposed, 'session_version' => 1,
            'state' => 'pending', 'reason' => 'Synthetic request', 'submit_key' => (string) Str::ulid(), 'submit_hash' => str_repeat('a', 64),
            'impact' => json_encode(['before' => ['starts_on' => $starts, 'ends_on' => $ends],
                'proposed' => ['starts_on' => $starts, 'ends_on' => $proposed], 'class_offerings' => 0], JSON_THROW_ON_ERROR)]);

        $folder = sys_get_temp_dir().'/edu-review-'.bin2hex(random_bytes(8));
        mkdir($folder, 0700);
        $jobs = [];
        $env = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'edu_test',
            'DB_HOST' => config('database.connections.mysql.host'), 'DB_PORT' => (string) config('database.connections.mysql.port'),
            'DB_USERNAME' => config('database.connections.mysql.username'), 'DB_PASSWORD' => config('database.connections.mysql.password'),
            'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array'];
        try {
            foreach (['approve', $second] as $index => $decision) {
                $actor = User::create(['name' => 'Synthetic reviewer', 'password' => 'Synthetic-Reviewer-Password']);
                $actor->forceFill(['is_platform_admin' => true])->save();
                app('session')->forgetDrivers();
                $session = app('session')->driver();
                $session->start();
                $request = request();
                $request->setLaravelSession($session);
                $request->setUserResolver(fn () => $actor);
                $request->attributes->set('request_id', 'synthetic-platform-preview');
                app(PlatformFirstFactor::class)->execute($request, ['identifier' => $actor->id, 'password' => 'Synthetic-Reviewer-Password']);
                $setup = app(PlatformMfa::class)->enroll($request, 'Synthetic-Reviewer-Password');
                app(PlatformMfa::class)->verify($request, 'Synthetic-Reviewer-Password', app(Totp::class)->code($setup['secret'], now()->timestamp), 'confirm');
                $preview = app(PlatformSessionReview::class)->preview($request, $id);
                $this->assertNotNull($preview['preview_token']);
                $body = ['decision' => $decision, 'reason' => 'Synthetic race decision', 'version' => 1];
                if ($decision === 'approve') {
                    $body['preview_token'] = $preview['preview_token'];
                }
                $input = ['actor' => $actor->id, 'id' => $id, 'body' => $body, 'key' => (string) Str::ulid(),
                    'session_id' => $session->getId(), 'session' => $session->all(),
                    'ready' => $folder.'/ready'.$index, 'gate' => $folder.'/go'];
                $job = new Process([PHP_BINARY, base_path('../../scripts/platform-session-review-race-worker.php')], base_path(), $env,
                    json_encode($input, JSON_THROW_ON_ERROR), 20);
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
                $this->assertSame(0, $job->wait(), $job->getErrorOutput());
                $results[] = trim($job->getOutput());
            }

            $state = DB::table('session_change_requests')->where('id', $id)->value('state');
            $this->assertContains($state, $second === 'approve' ? ['approved'] : ['approved', 'rejected']);
            $this->assertEqualsCanonicalizing([$state, 'conflict'], $results);
            $this->assertDatabaseHas('session_change_requests', ['id' => $id, 'version' => 2]);
            $this->assertDatabaseHas('academic_sessions', ['id' => $sessionId, 'ends_on' => $state === 'approved' ? $proposed : $ends,
                'version' => $state === 'approved' ? 2 : 1]);
            $this->assertSame(1, DB::table('audit_events')->where('resource_id', $id)->count());
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
