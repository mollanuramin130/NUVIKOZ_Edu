<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Internal policy only: no issuer, public route or production enablement. */
class PlatformAssurance
{
    public function allows(Request $request, string $capability): bool
    {
        if (! app()->environment('testing') || config('platform.synthetic_auth') !== true
            || $capability !== 'platform.health' || ! $request->hasSession()
            || $request->hasHeader('Authorization') || $request->hasHeader('X-School-ID')) {
            return false;
        }
        $session = $request->session();
        if ($session->has('school_id') || $session->has('mfa_assurance')) {
            return false;
        }
        $user = $request->user();
        $user = $user ? User::find($user->id) : null;
        if (! $user || ! $user->is_platform_admin || $user->status !== 'active'
            || $user->activation_required || $user->must_change_password) {
            return false;
        }
        $now = now()->timestamp;
        $context = $session->get('platform_context');
        $assurance = $session->get('platform_assurance');
        if (! is_array($context) || ! is_array($assurance)) {
            return false;
        }
        foreach (['issued_at' => 43200, 'seen_at' => 1800] as $key => $limit) {
            $time = $context[$key] ?? null;
            if (! is_int($time) || $time > $now || $now - $time >= $limit) {
                return false;
            }
        }
        $at = $assurance['at'] ?? null;
        $credential = DB::table('mfa_credentials')->where('user_id', $user->id)->whereNotNull('confirmed_at')->first();

        return $credential && is_int($at) && $at <= $now && $now - $at < 900
            && ($context['user_id'] ?? null) === $user->id
            && ($context['credential'] ?? null) === hash('sha256', $user->password)
            && ($assurance['context'] ?? null) === 'platform'
            && ($assurance['user_id'] ?? null) === $user->id
            && ($assurance['version'] ?? null) === $credential->version
            && ($assurance['session'] ?? null) === hash('sha256', $session->getId())
            && ! DB::table('session_revocations')->where('session_hash', hash('sha256', $session->getId()))->exists();
    }
}
