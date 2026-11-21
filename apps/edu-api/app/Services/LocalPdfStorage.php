<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\School;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

class LocalPdfStorage
{
    private const TABLE = 'local_pdf_objects';

    public function __construct(private readonly SchoolEntitlements $quota) {}

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
                $this->verifyStored($path, $row);

                return ['object_id' => $row->id, 'status' => 'published', 'bytes' => $row->bytes, 'replayed' => true];
            }
            abort_unless($row->state === 'quarantined' && Carbon::parse($row->expires_at)->isFuture(), 409);
            $this->removeCandidate($path);
            try {
                $this->write($body, $path);
                $this->inspect($path);
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

    private function inspect(string $path): void
    {
        $data = file_get_contents($path);
        abort_unless(is_string($data) && preg_match('/\A%PDF-(?:1\.[0-7]|2\.0)\r?\n/', $data)
            && preg_match('/startxref\s+([0-9]{1,8})\s+%%EOF\s*\z/', $data, $xref)
            && preg_match('/\A(?:xref\s|[0-9]+\s+[0-9]+\s+obj\b)/', substr($data, (int) $xref[1], 64))
            && (new \finfo(FILEINFO_MIME_TYPE))->file($path) === 'application/pdf', 422, 'Invalid PDF.');
        $process = new Process([config('pdf-storage.pdfinfo'), '-js', $path], env: ['LC_ALL' => 'C'], timeout: 5);
        try {
            $process->run();
        } catch (ExceptionInterface) {
            abort(503, 'PDF inspection is unavailable.');
        }
        abort_unless($process->isSuccessful() && trim($process->getErrorOutput()) === '' && trim($process->getOutput()) === '', 422, 'PDF inspection rejected the document.');
        $info = new Process([config('pdf-storage.pdfinfo'), $path], env: ['LC_ALL' => 'C'], timeout: 5);
        try {
            $info->run();
        } catch (ExceptionInterface) {
            abort(503, 'PDF inspection is unavailable.');
        }
        $metadata = $info->getOutput();
        abort_unless($info->isSuccessful() && trim($info->getErrorOutput()) === ''
            && preg_match('/^Pages:\s+[1-9]\d*\s*$/m', $metadata)
            && preg_match('/^Encrypted:\s+no\s*$/m', $metadata)
            && preg_match('/^Form:\s+none\s*$/m', $metadata), 422, 'PDF inspection rejected the document.');
    }

    private function removeCandidate(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            abort_unless(@unlink($path), 503);
        }
    }

    private function verifyStored(string $path, object $row): void
    {
        abort_unless(! is_link($path) && is_file($path) && filesize($path) === $row->bytes && hash_file('sha256', $path) === $row->sha256, 503, 'Stored PDF needs reconciliation.');
    }

    public function open(string $school, string $actor, string $object)
    {
        $root = $this->root();
        $this->member($school, $actor);
        $row = DB::table(self::TABLE)->where(['school_id' => $school, 'id' => $object, 'state' => 'published'])->firstOrFail();
        $path = $this->path($root, $row->id);
        $this->verifyStored($path, $row);
        $stream = @fopen($path, 'rb');
        abort_unless(is_resource($stream), 503);

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
