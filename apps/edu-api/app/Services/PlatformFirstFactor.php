<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Internal synthetic-only command. No real operator bootstrap or public issuer. */
class PlatformFirstFactor
{
    public function execute(Request $request, array $input): array
    {
        abort_unless(app()->environment('testing') && config('platform.synthetic_auth') === true, 403);
        abort_unless($request->hasSession() && ! $request->hasHeader('Authorization') && ! $request->hasHeader('X-School-ID') && ! $request->session()->has('school_id'), 403);
        Validator::make($input, ['identifier' => ['required', 'ulid'], 'password' => ['required', 'string', 'max:72']])->validate();
        abort_unless(array_diff(array_keys($input), ['identifier', 'password']) === [], 422);
        $key = 'platform-login:'.hash('sha256', (string) $request->ip());
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429);
        RateLimiter::hit($key, 60);
        $user = DB::transaction(function () use ($input, $request) {
            $user = User::whereKey($input['identifier'])->lockForUpdate()->first();
            $hash = $user?->password ?? Hash::make('Synthetic-unusable-'.Str::random(32));
            $valid = Hash::check($input['password'], $hash);
            abort_unless($valid && $user && $user->is_platform_admin && $user->status === 'active' && ! $user->activation_required && ! $user->must_change_password, 401);
            DB::table('platform_security_audits')->insert(['id' => (string) Str::ulid(), 'actor_user_id' => $user->id, 'action' => 'platform.first_factor_verified', 'request_id' => substr((string) $request->attributes->get('request_id', 'platform-command'), 0, 100), 'created_at' => now()]);

            return $user;
        }, 3);
        // Session changes occur only after durable audit success. First factor grants no assurance.
        $session = $request->session();
        $session->flush();
        Auth::guard('web')->login($user);
        $session->regenerate(true);
        $session->regenerateToken();
        $session->put('platform_context', ['user_id' => $user->id, 'credential' => hash('sha256', $user->password), 'issued_at' => now()->timestamp, 'seen_at' => now()->timestamp, 'challenge_expires_at' => now()->addMinutes(5)->timestamp]);

        return ['mfa_required' => true, 'csrf_token' => $session->token()];
    }
}
