<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/** Synthetic-only internal flow; HTTP/public issuer is not enabled. */
class PlatformMfa
{
    private function current(Request $r): User
    {
        abort_unless(app()->environment('testing') && config('platform.synthetic_auth') === true && $r->hasSession() && ! $r->hasHeader('Authorization') && ! $r->hasHeader('X-School-ID'), 403);
        $s = $r->session();
        $c = $s->get('platform_context');
        abort_unless(is_array($c) && ! $s->has('school_id') && ! $s->has('mfa_assurance'), 403);
        $u = User::whereKey(Auth::guard('web')->id())->firstOrFail();
        $now = now()->timestamp;
        abort_unless($u->is_platform_admin && $u->status === 'active' && ! $u->activation_required && ! $u->must_change_password
            && ($c['user_id'] ?? null) === $u->id && ($c['credential'] ?? null) === hash('sha256', $u->password)
            && is_int($c['issued_at'] ?? null) && $c['issued_at'] <= $now && $now - $c['issued_at'] < 43200
            && is_int($c['seen_at'] ?? null) && $c['seen_at'] <= $now && $now - $c['seen_at'] < 1800
            && is_int($c['challenge_expires_at'] ?? null) && $c['challenge_expires_at'] > $now
            && ! DB::table('session_revocations')->where('session_hash', hash('sha256', $s->getId()))->exists(), 403);

        return $u;
    }

    private function mutate(Request $r, string $password, callable $action): array
    {
        $u = $this->current($r);
        $key = 'platform-mfa:'.$u->id;
        abort_if(RateLimiter::tooManyAttempts($key, 10), 429);
        RateLimiter::hit($key, 300);

        return DB::transaction(function () use ($u, $r, $password, $action) {
            User::whereKey($u->id)->lockForUpdate()->firstOrFail();
            $u = $this->current($r);
            abort_unless(strlen($password) <= 72 && Hash::check($password, $u->password), 422);

            return $action($u);
        }, 3);
    }

    private function audit(User $u, Request $r, string $action): void
    {
        DB::table('platform_security_audits')->insert(['id' => (string) Str::ulid(), 'actor_user_id' => $u->id, 'action' => $action, 'request_id' => substr((string) $r->attributes->get('request_id', 'platform-command'), 0, 100), 'created_at' => now()]);
    }

    public function enroll(Request $r, string $password): array
    {
        return $this->mutate($r, $password, function ($u) use ($r) {
            $row = DB::table('mfa_credentials')->where('user_id', $u->id)->lockForUpdate()->first();
            abort_if($row?->confirmed_at, 403);
            $seed = app(Totp::class)->seed();
            DB::table('mfa_credentials')->updateOrInsert(['user_id' => $u->id], ['secret_encrypted' => Crypt::encryptString($seed), 'version' => (string) Str::ulid(), 'pending_expires_at' => now()->addMinutes(10), 'confirmed_at' => null, 'last_counter' => null, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($u, $r, 'platform.mfa_enrollment_started');

            return ['secret' => $seed, 'expires_in' => 600];
        });
    }

    public function verify(Request $r, string $password, string $code, string $mode): array
    {
        $result = $this->mutate($r, $password, function ($u) use ($r, $code, $mode) {
            abort_unless(in_array($mode, ['confirm', 'totp', 'recovery'], true), 422);
            $row = DB::table('mfa_credentials')->where('user_id', $u->id)->lockForUpdate()->first();
            abort_unless($row, 422);
            if ($mode === 'confirm') {
                abort_unless(! $row->confirmed_at && $row->pending_expires_at && now()->lessThan($row->pending_expires_at), 422);
            } else {
                abort_unless($row->confirmed_at, 422);
            }
            if ($mode === 'recovery') {
                abort_unless(preg_match('/\A[a-f0-9]{32}\z/', $code), 422);
                $proof = DB::table('mfa_recovery_codes')->where('user_id', $u->id)->where('code_hash', hash('sha256', $code))->whereNull('consumed_at')->lockForUpdate()->first();
                abort_unless($proof, 422);
                DB::table('mfa_recovery_codes')->where('id', $proof->id)->update(['consumed_at' => now(), 'updated_at' => now()]);
            } else {
                $counter = app(Totp::class)->counter(Crypt::decryptString($row->secret_encrypted), $code, now()->timestamp, $row->last_counter === null ? null : (int) $row->last_counter);
                abort_unless($counter !== null, 422);
                DB::table('mfa_credentials')->where('user_id', $u->id)->update(['last_counter' => $counter, 'confirmed_at' => $row->confirmed_at ?? now(), 'pending_expires_at' => null, 'updated_at' => now()]);
            }
            $codes = [];
            if ($mode === 'confirm') {
                DB::table('mfa_recovery_codes')->where('user_id', $u->id)->delete();
                for ($i = 0; $i < 8; $i++) {
                    $new = bin2hex(random_bytes(16));
                    DB::table('mfa_recovery_codes')->insert(['id' => (string) Str::ulid(), 'user_id' => $u->id, 'code_hash' => hash('sha256', $new), 'created_at' => now(), 'updated_at' => now()]);
                    $codes[] = $new;
                }
                app(SecurityRevocation::class)->user($u->id);
            }
            $this->audit($u, $r, 'platform.mfa_'.$mode);

            return ['user_id' => $u->id, 'version' => $row->version, 'recovery_codes' => $codes];
        });
        $r->session()->regenerate(true);
        $r->session()->regenerateToken();
        $r->session()->put('platform_assurance', ['context' => 'platform', 'user_id' => $result['user_id'], 'version' => $result['version'], 'session' => hash('sha256', $r->session()->getId()), 'at' => now()->timestamp]);

        return ['verified' => true, 'recovery_codes' => $result['recovery_codes'], 'csrf_token' => $r->session()->token()];
    }
}
