<?php

namespace Tests;

use App\Models\Membership;
use App\Services\PrivilegedMfa;
use App\Services\Totp;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    // Existing strongly authenticated session fixture for non-MFA-focused service tests.
    // No production bypass/token issuer; MFA acceptance tests exercise real enrollment/challenges.
    protected function mfaFixture(Membership $member): void
    {
        if ($member->role->value !== 'school_admin') {
            return;
        }
        $version = (string) Str::ulid();
        DB::table('mfa_credentials')->updateOrInsert(['user_id' => $member->user_id], ['secret_encrypted' => Crypt::encryptString(app(Totp::class)->seed()), 'version' => $version, 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $session = app('session')->driver();
        $session->start();
        request()->setLaravelSession($session);
        Auth::guard('web')->login($member->user);
        $session->put(['school_id' => $member->school_id, 'credential_hash' => hash('sha256', $member->user->password), 'issued_at' => now()->timestamp, 'seen_at' => now()->timestamp, 'mfa_challenge_expires_at' => now()->addMinutes(5)->timestamp]);
        $session->put('mfa_assurance', ['version' => $version, 'session' => hash('sha256', $session->getId()), 'binding' => app(PrivilegedMfa::class)->binding($member), 'at' => now()->timestamp]);
    }
}
