<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class SecurityRevocation
{
    // Current session storage has no indexed school metadata. Membership security changes
    // conservatively revoke this identity's browser sessions globally, not other users'.
    public function user(string $user, ?string $school = null): void
    {
        foreach (DB::table('sessions')->where('user_id', $user)->get(['id']) as $session) {
            DB::table('session_revocations')->insertOrIgnore(['session_hash' => hash('sha256', $session->id), 'user_id' => $user, 'created_at' => now()]);
        }
        DB::table('sessions')->where('user_id', $user)->delete();
        $tokens = User::findOrFail($user)->tokens();
        if ($school !== null) {
            $tokens->whereJsonContains('abilities', 'school:'.$school);
        }
        $tokens->delete();
    }
}
