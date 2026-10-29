<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PrivilegedMfa
{
    public function binding(Membership $m): string
    {
        $m->load(['user', 'school']);

        return hash('sha256', json_encode([$m->id, $m->school_id, $m->user_id, $m->role->value, $m->status, $m->login_identifier, $m->user->password, $m->user->status, $m->user->activation_required, $m->user->must_change_password, $m->user->is_platform_admin], JSON_THROW_ON_ERROR));
    }

    public function verified(Membership $m, Request $r): bool
    {
        if (! $r->hasSession() || $m->user->status !== 'active' || $m->status !== 'active' || $m->user->activation_required || $m->user->must_change_password || $m->user->is_platform_admin || in_array($m->school->access_state, ['suspended', 'archived'], true) || ! hash_equals((string) $r->session()->get('credential_hash', ''), hash('sha256', $m->user->password)) || now()->timestamp - $r->session()->get('issued_at', 0) >= 43200 || now()->timestamp - $r->session()->get('seen_at', 0) >= 1800 || Auth::guard('web')->id() !== $m->user_id || $r->session()->get('school_id') !== $m->school_id) {
            return false;
        }
        $proof = $r->session()->get('mfa_assurance');
        $credential = DB::table('mfa_credentials')->where('user_id', $m->user_id)->whereNotNull('confirmed_at')->first();

        return $credential && is_array($proof) && ($proof['version'] ?? null) === $credential->version && ($proof['session'] ?? null) === hash('sha256', $r->session()->getId()) && ($proof['at'] ?? 0) <= now()->timestamp && now()->timestamp - ($proof['at'] ?? 0) < 900 && hash_equals($this->binding($m), (string) ($proof['binding'] ?? ''));
    }

    public function require(Membership $m): void
    {
        abort_unless($this->verified($m, request()), 403, 'MFA required.');
    }

    private function eligible(Membership $m, Request $r): Membership
    {
        $current = Membership::whereKey($m->id)->where('school_id', $m->school_id)->where('user_id', $m->user_id)->with(['user', 'school'])->firstOrFail();
        abort_unless($r->hasSession() && Auth::guard('web')->id() === $current->user_id && $r->session()->get('school_id') === $current->school_id && hash_equals((string) $r->session()->get('credential_hash', ''), hash('sha256', $current->user->password)) && $current->role === Role::SchoolAdmin && $current->status === 'active' && $current->user->status === 'active' && ! $current->user->activation_required && ! $current->user->must_change_password && ! $current->user->is_platform_admin && ! in_array($current->school->access_state, ['suspended', 'archived'], true) && now()->timestamp - $r->session()->get('issued_at', 0) < 43200 && now()->timestamp - $r->session()->get('seen_at', 0) < 1800, 403);

        return $current;
    }

    public function status(Membership $m, Request $r): array
    {
        $m = $this->eligible($m, $r);

        return ['enrolled' => DB::table('mfa_credentials')->where('user_id', $m->user_id)->whereNotNull('confirmed_at')->exists(), 'verified' => $this->verified($m, $r), 'assurance_seconds' => 900, 'challenge_expires_at' => $r->session()->get('mfa_challenge_expires_at')];
    }

    private function mutate(Membership $m, Request $r, string $password, callable $action): array
    {
        $m = $this->eligible($m, $r);
        $key = 'mfa:'.$m->user_id;
        abort_if(RateLimiter::tooManyAttempts($key, 10), 429);
        RateLimiter::hit($key, 300);

        return DB::transaction(function () use ($m, $r, $password, $action) {
            School::whereKey($m->school_id)->lockForUpdate()->firstOrFail();
            User::whereKey($m->user_id)->lockForUpdate()->firstOrFail();
            Membership::whereKey($m->id)->lockForUpdate()->firstOrFail();
            $current = $this->eligible($m, $r);
            if (! Hash::check($password, $current->user->password)) {
                $this->deny();
            }

            return $action($current);
        }, 3);
    }

    private function deny(): never
    {
        throw ValidationException::withMessages(['mfa' => 'The MFA credential, code or challenge is invalid, expired or unavailable.']);
    }

    private function audit(Membership $m, Request $r, string $action): void
    {
        AuditEvent::create(['school_id' => $m->school_id, 'actor_user_id' => $m->user_id, 'action' => $action, 'resource_id' => $m->user_id, 'request_id' => $r->attributes->get('request_id', 'mfa-command')]);
    }

    public function enroll(Membership $m, Request $r, string $password): array
    {
        return $this->mutate($m, $r, $password, function ($current) use ($r) {
            $row = DB::table('mfa_credentials')->where('user_id', $current->user_id)->lockForUpdate()->first();
            abort_if($row?->confirmed_at, 403);
            $seed = app(Totp::class)->seed();
            DB::table('mfa_credentials')->updateOrInsert(['user_id' => $current->user_id], ['secret_encrypted' => Crypt::encryptString($seed), 'version' => (string) Str::ulid(), 'pending_expires_at' => now()->addMinutes(10), 'confirmed_at' => null, 'last_counter' => null, 'created_at' => now(), 'updated_at' => now()]);
            $r->session()->forget('mfa_assurance');
            $this->audit($current, $r, 'mfa.enrollment_started');

            return ['secret' => $seed, 'otpauth_uri' => 'otpauth://totp/'.rawurlencode('NUVIKOZ Edu:'.$current->user_id).'?secret='.$seed.'&issuer=NUVIKOZ%20Edu&algorithm=SHA1&digits=6&period=30', 'expires_in' => 600];
        });
    }

    private function codes(string $user): array
    {
        DB::table('mfa_recovery_codes')->where('user_id', $user)->delete();
        $result = [];
        for ($i = 0; $i < 8; $i++) {
            $code = bin2hex(random_bytes(16));
            DB::table('mfa_recovery_codes')->insert(['id' => (string) Str::ulid(), 'user_id' => $user, 'code_hash' => hash('sha256', $code), 'created_at' => now(), 'updated_at' => now()]);
            $result[] = $code;
        }

        return $result;
    }

    public function verify(Membership $m, Request $r, string $password, string $code, string $mode): array
    {
        $result = $this->mutate($m, $r, $password, function ($current) use ($r, $code, $mode) {
            $row = DB::table('mfa_credentials')->where('user_id', $current->user_id)->lockForUpdate()->first();
            if (! $row || ! in_array($mode, ['confirm', 'totp', 'recovery'], true)) {
                $this->deny();
            }
            if ($mode === 'confirm') {
                if ($row->confirmed_at || ! $row->pending_expires_at || now()->greaterThanOrEqualTo($row->pending_expires_at)) {
                    $this->deny();
                }
            } elseif (! $row->confirmed_at || $r->session()->get('mfa_challenge_expires_at', 0) <= now()->timestamp) {
                $this->deny();
            }
            if ($mode === 'recovery') {
                if (! preg_match('/\A[a-f0-9]{32}\z/', $code)) {
                    $this->deny();
                }
                $recovery = DB::table('mfa_recovery_codes')->where('user_id', $current->user_id)->where('code_hash', hash('sha256', $code))->whereNull('consumed_at')->lockForUpdate()->first();
                if (! $recovery) {
                    $this->deny();
                }
                DB::table('mfa_recovery_codes')->where('id', $recovery->id)->update(['consumed_at' => now(), 'updated_at' => now()]);
            } else {
                // Decryption failure throws and rolls back, never downgrades to password-only.
                $step = app(Totp::class)->counter(Crypt::decryptString($row->secret_encrypted), $code, now()->timestamp, $row->last_counter === null ? null : (int) $row->last_counter);
                if ($step === null) {
                    $this->deny();
                }
                DB::table('mfa_credentials')->where('user_id', $current->user_id)->update(['last_counter' => $step, 'confirmed_at' => $row->confirmed_at ?? now(), 'pending_expires_at' => null, 'updated_at' => now()]);
            }
            $codes = $mode === 'confirm' ? $this->codes($current->user_id) : [];
            if ($mode === 'confirm') {
                DB::table('sessions')->where('user_id', $current->user_id)->delete();
                $current->user->tokens()->delete();
            }
            $this->audit($current, $r, $mode === 'confirm' ? 'mfa.enrolled' : 'mfa.verified');

            return ['verified' => true, 'version' => $row->version, 'recovery_codes' => $codes];
        });
        // Session assurance is written only after the database transaction commits successfully.
        $r->session()->regenerate(true);
        $r->session()->regenerateToken();
        $r->session()->put('mfa_assurance', ['version' => $result['version'], 'session' => hash('sha256', $r->session()->getId()), 'binding' => $this->binding($m), 'at' => now()->timestamp]);
        unset($result['version']);
        $result['csrf_token'] = $r->session()->token();

        return $result;
    }

    public function regenerate(Membership $m, Request $r, string $password): array
    {
        return $this->mutate($m, $r, $password, function ($current) use ($r) {
            abort_unless($this->verified($current, $r), 403);
            $codes = $this->codes($current->user_id);
            $this->audit($current, $r, 'mfa.recovery_codes_regenerated');

            return ['recovery_codes' => $codes];
        });
    }
}
