<?php

namespace App\Services;

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

class MfaReplacement
{
    private function command(Request $r, string $context, string $password, callable $action): array
    {
        abort_unless($r->hasSession() && ! $r->hasHeader('Authorization') && in_array($context, ['school', 'platform'], true), 403);
        $id = Auth::guard('web')->id();
        abort_unless($id, 403);
        $key = 'mfa-replacement:'.$id;
        abort_if(RateLimiter::tooManyAttempts($key, 10), 429);
        RateLimiter::hit($key, 300);

        return DB::transaction(function () use ($r, $context, $password, $action, $id) {
            $member = null;
            if ($context === 'school') {
                abort_if($r->session()->has('platform_context') || $r->session()->has('platform_assurance'), 403);
                School::whereKey($r->session()->get('school_id'))->lockForUpdate()->firstOrFail();
            }
            $user = User::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($context === 'school') {
                $member = Membership::where('user_id', $id)->where('school_id', $r->session()->get('school_id'))->lockForUpdate()->firstOrFail();
                abort_if($r->hasHeader('X-School-ID') && $r->header('X-School-ID') !== $member->school_id, 403);
                app(PrivilegedMfa::class)->status($member, $r); // Current role, identity, school, credential and session checks.
                $binding = app(PrivilegedMfa::class)->binding($member);
            } else {
                $user = app(PlatformMfa::class)->authenticatedIdentity($r); // Testing-only issuer gate remains mandatory.
                $binding = hash('sha256', $user->id.'|'.$user->password.'|platform');
            }
            abort_unless(strlen($password) <= 72 && Hash::check($password, $user->password), 422);
            $credential = DB::table('mfa_credentials')->where('user_id', $id)->lockForUpdate()->first();
            abort_unless($credential?->confirmed_at, 422);

            return $action($user, $member, $binding, $credential);
        }, 3);
    }

    private function audit(User $user, ?Membership $member, Request $r, string $action): void
    {
        $request = substr((string) $r->attributes->get('request_id', 'mfa-replacement'), 0, 100);
        if ($member) {
            AuditEvent::create(['school_id' => $member->school_id, 'actor_user_id' => $user->id, 'action' => $action, 'resource_id' => $user->id, 'request_id' => $request]);
        } else {
            DB::table('platform_security_audits')->insert(['id' => (string) Str::ulid(), 'actor_user_id' => $user->id, 'action' => 'platform.'.$action, 'request_id' => $request, 'created_at' => now()]);
        }
    }

    public function start(Request $r, string $context, string $password, string $code, string $method): array
    {
        return $this->command($r, $context, $password, function ($user, $member, $binding, $credential) use ($r, $context, $code, $method) {
            $pending = DB::table('mfa_replacements')->where('user_id', $user->id)->lockForUpdate()->first();
            abort_if($pending && now()->lessThan($pending->expires_at), 409);
            abort_unless(in_array($method, ['totp', 'recovery'], true), 422);
            if ($method === 'recovery') {
                abort_unless(preg_match('/\A[a-f0-9]{32}\z/', $code), 422);
                $proof = DB::table('mfa_recovery_codes')->where('user_id', $user->id)->where('code_hash', hash('sha256', $code))->whereNull('consumed_at')->lockForUpdate()->first();
                abort_unless($proof, 422);
                DB::table('mfa_recovery_codes')->where('id', $proof->id)->update(['consumed_at' => now(), 'updated_at' => now()]);
            } else {
                $counter = app(Totp::class)->counter(Crypt::decryptString($credential->secret_encrypted), $code, now()->timestamp, $credential->last_counter === null ? null : (int) $credential->last_counter);
                abort_unless($counter !== null, 422);
                DB::table('mfa_credentials')->where('user_id', $user->id)->update(['last_counter' => $counter, 'updated_at' => now()]);
            }
            $id = (string) Str::ulid();
            $seed = app(Totp::class)->seed();
            DB::table('mfa_replacements')->where('user_id', $user->id)->delete();
            DB::table('mfa_replacements')->insert(['id' => $id, 'user_id' => $user->id, 'context' => $context, 'binding' => $binding, 'session_hash' => hash('sha256', $r->session()->getId()), 'old_version' => $credential->version, 'secret_encrypted' => Crypt::encryptString($seed), 'expires_at' => now()->addMinutes(5), 'created_at' => now(), 'updated_at' => now()]);
            $this->audit($user, $member, $r, 'mfa.replacement_started');

            return ['replacement_id' => $id, 'secret' => $seed, 'expires_in' => 300];
        });
    }

    public function confirm(Request $r, string $context, string $password, string $id, string $code): array
    {
        $codes = $this->command($r, $context, $password, function ($user, $member, $binding, $credential) use ($r, $context, $id, $code) {
            $pending = DB::table('mfa_replacements')->where('user_id', $user->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($pending && $pending->context === $context && hash_equals($pending->binding, $binding)
                && hash_equals($pending->session_hash, hash('sha256', $r->session()->getId()))
                && $pending->old_version === $credential->version && now()->lessThan($pending->expires_at), 422);
            $counter = app(Totp::class)->counter(Crypt::decryptString($pending->secret_encrypted), $code, now()->timestamp, null);
            abort_unless($counter !== null, 422);
            DB::table('mfa_credentials')->where('user_id', $user->id)->update(['secret_encrypted' => $pending->secret_encrypted, 'version' => (string) Str::ulid(), 'last_counter' => $counter, 'confirmed_at' => now(), 'pending_expires_at' => null, 'updated_at' => now()]);
            DB::table('mfa_recovery_codes')->where('user_id', $user->id)->delete();
            $codes = [];
            for ($n = 0; $n < 8; $n++) {
                $new = bin2hex(random_bytes(16));
                DB::table('mfa_recovery_codes')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->id, 'code_hash' => hash('sha256', $new), 'created_at' => now(), 'updated_at' => now()]);
                $codes[] = $new;
            }
            DB::table('mfa_replacements')->where('user_id', $user->id)->delete();
            DB::table('session_revocations')->insertOrIgnore(['session_hash' => hash('sha256', $r->session()->getId()), 'user_id' => $user->id, 'created_at' => now()]);
            app(SecurityRevocation::class)->user($user->id);
            $this->audit($user, $member, $r, 'mfa.replaced');

            return $codes;
        });
        // Never retain privileged assurance or authenticate the newly enrolled factor implicitly.
        Auth::guard('web')->logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return ['recovery_codes' => $codes, 'reauthentication_required' => true, 'csrf_token' => $r->session()->token()];
    }
}
