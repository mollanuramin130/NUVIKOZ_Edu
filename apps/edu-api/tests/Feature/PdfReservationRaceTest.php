<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Services\SchoolEntitlements;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PdfReservationRaceTest extends TestCase
{
    public static function limits(): array
    {
        return ['daily slot' => [false], 'physical bytes' => [true]];
    }

    #[DataProvider('limits')]
    public function test_two_connections_contest_the_last_capacity(bool $physical): void
    {
        if (DB::getDriverName() !== 'mysql' || DB::getDatabaseName() !== 'edu_test' || ! function_exists('proc_open')) {
            $this->markTestSkipped('Requires disposable MariaDB and process support.');
        }
        $this->artisan('migrate:fresh')->assertExitCode(0);
        RefreshDatabaseState::$migrated = false;
        $this->beforeApplicationDestroyed(function (): void {
            $this->artisan('migrate:fresh')->assertExitCode(0);
            RefreshDatabaseState::$migrated = false;
        });
        $school = School::create(['code' => 'PDF-RACE', 'name' => 'PDF Race', 'data_stage' => 'live', 'access_state' => 'live_enabled',
            'entitlement_ends_at' => now()->addDays(30), 'settings' => ['timezone' => 'Asia/Kolkata']]);
        $user = User::create(['name' => 'Synthetic admin', 'password' => Str::random(32)]);
        Membership::create(['school_id' => $school->id, 'user_id' => $user->id, 'role' => 'school_admin', 'login_identifier' => 'ADMIN', 'status' => 'active']);
        DB::table('school_entitlement_events')->insert([
            'id' => (string) Str::ulid(), 'school_id' => $school->id, 'actor_id' => $user->id,
            'command_id' => (string) Str::uuid(), 'sequence' => 1, 'action' => 'grant_free_live',
            'policy' => 'free-live-v1', 'term_days' => 30, 'starts_at' => now(), 'ends_at' => now()->addDays(30),
        ]);
        for ($i = 0; ! $physical && $i < 9; $i++) {
            app(SchoolEntitlements::class)->reservePdf($school->id, (string) Str::ulid(), 1, (string) Str::uuid(), $user->id, 'race-seed');
        }

        $script = <<<'CHILD'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo "ready\n";
try {
    if ($argv[5] === '') {
        $app->make(App\Services\SchoolEntitlements::class)->reservePdf($argv[1], $argv[2], 1, $argv[3], $argv[4], 'race');
    } else {
        $app->useStoragePath($argv[5]);
        $path = base_path('../../assets/demo-samples/report-card.pdf');
        config(['pdf-storage.enabled' => true, 'pdf-storage.require_malware_scan' => false, 'pdf-storage.capacity_bytes' => filesize($path)]);
        $app->make(App\Services\LocalPdfStorage::class)->ingest($argv[1], $argv[2], $argv[3], $argv[4], fopen($path, 'rb'), 'race');
    }
    echo "accepted\n";
} catch (Symfony\Component\HttpKernel\Exception\HttpException $error) {
    echo $error->getStatusCode()."\n";
}
CHILD;
        $workers = [];
        $root = $physical ? sys_get_temp_dir().'/edu-pdf-race-'.bin2hex(random_bytes(12)) : '';
        DB::beginTransaction();
        try {
            if ($physical) {
                DB::table('local_pdf_capacity')->where('id', 1)->lockForUpdate()->firstOrFail();
            } else {
                School::whereKey($school->id)->lockForUpdate()->firstOrFail();
            }
            for ($i = 0; $i < 2; $i++) {
                $worker = new Process([PHP_BINARY, '-c', php_ini_loaded_file() ?: '', '-r', $script, $school->id,
                    (string) Str::ulid(), (string) Str::uuid(), $user->id, $root], base_path(), timeout: 12);
                $workers[] = $worker;
                $worker->start();
                $this->assertTrue($worker->waitUntil(fn ($type, $output) => str_contains($output, 'ready')), $worker->getErrorOutput());
            }
            DB::commit();
            $results = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $results[] = trim(str_replace('ready', '', $worker->getOutput()));
            }
            $this->assertEqualsCanonicalizing(['accepted', $physical ? '503' : '409'], $results);
            $this->assertDatabaseCount('school_pdf_reservations', $physical ? 1 : 10);
            $this->assertDatabaseCount('local_pdf_objects', $physical ? 1 : 0);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            if ($physical) {
                File::deleteDirectory($root);
            }
        }
    }
}
