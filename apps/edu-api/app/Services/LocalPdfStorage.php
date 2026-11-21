<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\School;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LocalPdfStorage
{
    private const TABLE = 'local_pdf_objects';

    public function __construct(private readonly SchoolEntitlements $quota, private readonly PdfInspection $inspection) {}

    private function root(): string
    {
        abort_unless(app()->environment(['local', 'testing']) && config('pdf-storage.enabled'), 503, 'Local PDF storage is unavailable.');
        $root = storage_path('app/private/pdf-objects');
        abort_if(is_link($root), 503);
        abort_unless(is_dir($root) || @mkdir($root, 0700, true) || is_dir($root), 503);
        abort_unless(realpath($root) === $root && (fileperms($root) & 0077) === 0, 503);

        return $root;
    }

    private function member(string $school, string $actor): void
    {
        $membership = Membership::where('school_id', $school)->where('user_id', $actor)->first();
        abort_unless($membership, 403);
        app(SchoolAccess::class)->requireAdminIdentity($membership);
    }

    private function lock(): void
    {
        DB::table('local_pdf_capacity')->where('id', 1)->lockForUpdate()->firstOrFail();
    }

    private function path(string $root, string $id): string
    {
        abort_unless(preg_match('/\A[a-f0-9]{48}\z/', $id), 404);

        return $root.'/'.$id;
    }

    /**
     * Internal trusted caller only. The caller must already authorize the document binding
     * and supply a server-owned stream. No browser or URL entry point is registered.
     */
    public function ingest(string $school, string $document, string $command, string $actor, $stream, string $request): array
    {
        abort_unless(is_resource($stream) && get_resource_type($stream) === 'stream' && Str::isUlid($document) && Str::isUuid($command), 422);
        $root = $this->root();
        $this->member($school, $actor);
        $body = stream_get_contents($stream, SchoolEntitlements::FREE['max_pdf_bytes'] + 1);
        abort_unless(is_string($body) && $body !== '' && feof($stream)
            && strlen($body) <= SchoolEntitlements::FREE['max_pdf_bytes'], 422, 'Invalid or oversized PDF stream.');
        $bytes = strlen($body);
        $digest = hash('sha256', $body);
        $key = ['school_id' => $school, 'command_id' => $command];

        // Commit the journal before opening any file. A crash leaves a counted, identifiable candidate.
        DB::transaction(function () use ($root, $school, $document, $command, $actor, $request, $key, $bytes, $digest): void {
            $this->lock();
            $row = DB::table(self::TABLE)->where($key)->lockForUpdate()->first();
            if ($row) {
                abort_unless($row->actor_id === $actor && $row->document_id === $document && $row->sha256 === $digest, 409);

                return;
            }
            $limit = config('pdf-storage.capacity_bytes');
            $used = DB::table(self::TABLE)->whereIn('state', ['quarantined', 'published'])->sum('bytes');
            $free = disk_free_space($root);
            abort_unless(is_int($limit) && $used + $bytes <= $limit && $free !== false && $free >= $bytes, 503, 'Private storage capacity is exhausted.');
            $reserve = $this->quota->reservePdf($school, $document, $bytes, $command, $actor, $request);
            abort_if($reserve['replayed'], 409);
            $expiry = DB::table('school_pdf_reservations')->where($key)->value('expires_at');
            DB::table(self::TABLE)->insert($key + [
                'id' => bin2hex(random_bytes(24)), 'actor_id' => $actor, 'document_id' => $document,
                'bytes' => $bytes, 'sha256' => $digest, 'state' => 'quarantined', 'expires_at' => $expiry,
            ]);
        }, 3);

        return DB::transaction(function () use ($root, $school, $command, $actor, $body, $bytes, $digest, $request, $key): array {
            $this->lock();
            School::whereKey($school)->lockForUpdate()->firstOrFail();
            $this->member($school, $actor);
            $row = DB::table(self::TABLE)->where($key)->lockForUpdate()->firstOrFail();
            $path = $this->path($root, $row->id);
            if ($row->state === 'published') {
                $this->verifiedBytes($path, $row);

                return ['object_id' => $row->id, 'status' => 'published', 'bytes' => $row->bytes, 'replayed' => true];
            }
            abort_unless($row->state === 'quarantined' && Carbon::parse($row->expires_at)->isFuture(), 409);
            $this->removeCandidate($path);
            try {
                $this->write($body, $path);
                $this->inspection->inspect($path);
                abort_unless(hash_file('sha256', $path) === $digest, 503);
                $this->quota->settlePdf($school, $command, $actor, $bytes, 'application/pdf', $request);
                DB::table(self::TABLE)->where('id', $row->id)->update(['state' => 'published', 'bytes' => $bytes, 'sha256' => $digest]);
            } catch (\Throwable $error) {
                $this->removeCandidate($path);
                throw $error;
            }

            return ['object_id' => $row->id, 'status' => 'published', 'bytes' => $bytes, 'replayed' => false];
        });
    }

    private function write(string $body, string $path): void
    {
        $permissions = umask(0077);
        try {
            $file = @fopen($path, 'xb');
        } finally {
            umask($permissions);
        }
        abort_unless(is_resource($file), 503);
        try {
            abort_unless(@fwrite($file, $body) === strlen($body) && fflush($file) && fsync($file), 503);
        } finally {
            fclose($file);
        }
    }

    private function removeCandidate(string $path): void
    {
        clearstatcache(true, $path);
        if (is_link($path) || file_exists($path)) {
            abort_unless((is_link($path) || is_file($path)) && @unlink($path), 503);
            clearstatcache(true, $path);
            abort_if(is_link($path) || file_exists($path), 503);
        }
    }

    private function verifiedBytes(string $path, object $row): string
    {
        clearstatcache(true, $path);
        $expected = @lstat($path);
        abort_unless($expected && ($expected['mode'] & 0170000) === 0100000
            && $expected['size'] === $row->bytes && $row->bytes <= SchoolEntitlements::FREE['max_pdf_bytes'], 503, 'Stored PDF needs reconciliation.');
        $source = @fopen($path, 'rb');
        abort_unless(is_resource($source), 503, 'Stored PDF needs reconciliation.');
        try {
            $opened = fstat($source);
            abort_unless($opened && $opened['ino'] === $expected['ino'] && $opened['dev'] === $expected['dev'], 503, 'Stored PDF needs reconciliation.');
            $body = stream_get_contents($source, $row->bytes + 1);
            abort_unless(is_string($body) && strlen($body) === $row->bytes
                && hash('sha256', $body) === $row->sha256, 503, 'Stored PDF needs reconciliation.');

            return $body;
        } finally {
            fclose($source);
        }
    }

    public function open(string $school, string $actor, string $object)
    {
        $root = $this->root();
        $this->member($school, $actor);
        $row = DB::table(self::TABLE)->where(['school_id' => $school, 'id' => $object, 'state' => 'published'])->firstOrFail();
        $body = $this->verifiedBytes($this->path($root, $row->id), $row);
        $stream = fopen('php://memory', 'w+b');
        abort_unless(is_resource($stream), 503);
        if (fwrite($stream, $body) !== strlen($body) || ! rewind($stream)) {
            fclose($stream);
            abort(503);
        }

        return $stream;
    }

    public function reconcile(): int
    {
        $root = $this->root();

        return DB::transaction(function () use ($root): int {
            $this->lock();
            $rows = DB::table(self::TABLE)->where('state', 'quarantined')->where('expires_at', '<=', now())->limit(100)->lockForUpdate()->get();
            foreach ($rows as $row) {
                $this->removeCandidate($this->path($root, $row->id));
                $this->quota->cancelPdf($row->school_id, $row->command_id, $row->actor_id, 'local-pdf-reconcile');
                DB::table(self::TABLE)->where('id', $row->id)->update(['state' => 'cancelled', 'bytes' => 0]);
            }

            return $rows->count();
        });
    }
}
