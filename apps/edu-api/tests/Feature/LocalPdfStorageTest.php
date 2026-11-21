<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Services\LocalPdfStorage;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LocalPdfStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_pdf_is_verified_and_committed_once_and_capacity_includes_retained_content(): void
    {
        $this->withStorage(function (LocalPdfStorage $storage, School $school, User $owner): void {
            $bytes = file_get_contents(base_path('../../assets/demo-samples/report-card.pdf'));
            $command = (string) Str::uuid();
            $document = (string) Str::ulid();
            $write = fn (string $body) => $storage->ingest($school->id, $document, $command, $owner->id, $this->stream($body), 'test');
            $first = $write($bytes);
            $this->assertFalse($first['replayed']);
            $this->assertSame(strlen($bytes), $first['bytes']);
            $this->assertTrue($write($bytes)['replayed']);
            $this->assertDatabaseCount('school_pdf_admissions', 1);
            $read = $storage->open($school->id, $owner->id, $first['object_id']);
            $this->assertSame($bytes, stream_get_contents($read));
            fclose($read);
            $this->assertSame(0600, fileperms(storage_path('app/private/pdf-objects/'.$first['object_id'])) & 0777);
            $otherSchool = School::create(['code' => 'OTHER', 'name' => 'Other school', 'access_state' => 'live_enabled']);
            Membership::create(['school_id' => $otherSchool->id, 'user_id' => $owner->id, 'role' => 'school_admin', 'login_identifier' => 'OWNER', 'status' => 'active']);
            $this->reject(fn () => $storage->open($otherSchool->id, $owner->id, $first['object_id']), 404);
            $this->reject(fn () => $storage->open($school->id, $owner->id, '../report-card.pdf'), 404);
            config(['pdf-storage.capacity_bytes' => strlen($bytes)]);
            $this->reject(fn () => $storage->ingest($school->id, (string) Str::ulid(), (string) Str::uuid(), $owner->id, $this->stream($bytes), 'test'), 503);
            $this->assertSame(0, $storage->reconcile());
            $this->assertDatabaseCount('school_pdf_admissions', 1);
        });
    }

    public function test_invalid_or_interrupted_candidates_are_private_and_cleaned_before_quota_release(): void
    {
        $this->withStorage(function (LocalPdfStorage $storage, School $school, User $owner): void {
            $command = (string) Str::uuid();
            $document = (string) Str::ulid();
            foreach (["%PDF-1.4\ntruncated", str_repeat('x', 5_000_001)] as $body) {
                $this->reject(fn () => $storage->ingest($school->id, (string) Str::ulid(), (string) Str::uuid(), $owner->id, $this->stream($body), 'test'), 422);
            }
            $this->reject(fn () => $storage->ingest($school->id, $document, $command, $owner->id, $this->stream('MZ disguised'), 'test'), 422);
            $row = DB::table('local_pdf_objects')->where('command_id', $command)->first();
            $this->assertSame('quarantined', $row->state);
            $this->reject(fn () => $storage->open($school->id, $owner->id, $row->id), 404);
            $path = storage_path('app/private/pdf-objects/'.$row->id);
            $this->assertFileDoesNotExist($path);
            file_put_contents($path, 'simulated interrupted write');
            config(['pdf-storage.capacity_bytes' => 20]);
            $this->reject(fn () => $storage->ingest($school->id, (string) Str::ulid(), (string) Str::uuid(), $owner->id, $this->stream('other'), 'test'), 503);
            $this->travel(15)->minutes();
            $this->assertSame(2, $storage->reconcile());
            $this->assertFileDoesNotExist($path);
            $this->assertSame(0, $storage->reconcile());
            $this->assertDatabaseHas('school_pdf_reservations', ['command_id' => $command, 'state' => 'cancelled']);
            $this->assertDatabaseCount('school_pdf_admissions', 0);
        });
    }

    private function withStorage(callable $test): void
    {
        $original = storage_path();
        $root = sys_get_temp_dir().'/edu-pdf-'.bin2hex(random_bytes(12));
        $this->app->useStoragePath($root);
        config(['pdf-storage.enabled' => true]);
        try {
            $actor = User::create(['name' => 'Synthetic admin', 'password' => Str::random(32)]);
            $school = School::create(['code' => 'PDF-LOCAL', 'name' => 'PDF Local', 'data_stage' => 'live', 'access_state' => 'live_enabled',
                'entitlement_ends_at' => now()->addDays(30), 'settings' => ['timezone' => 'Asia/Kolkata']]);
            Membership::create(['school_id' => $school->id, 'user_id' => $actor->id, 'role' => 'school_admin', 'login_identifier' => 'ADMIN', 'status' => 'active']);
            DB::table('school_entitlement_events')->insert([
                'id' => (string) Str::ulid(), 'school_id' => $school->id, 'actor_id' => $actor->id,
                'command_id' => (string) Str::uuid(), 'sequence' => 1, 'action' => 'grant_free_live',
                'policy' => 'free-live-v1', 'term_days' => 30, 'starts_at' => now(), 'ends_at' => now()->addDays(30),
            ]);
            $test(app(LocalPdfStorage::class), $school, $actor);
        } finally {
            $this->app->useStoragePath($original);
            File::deleteDirectory($root);
        }
    }

    private function stream(string $body)
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $body);
        rewind($stream);

        return $stream;
    }

    private function reject(callable $action, int $status): void
    {
        try {
            $action();
            $this->fail('Operation unexpectedly succeeded.');
        } catch (RecordNotFoundException|HttpException $error) {
            $this->assertSame($status, $error instanceof HttpException ? $error->getStatusCode() : 404);
        }
    }
}
