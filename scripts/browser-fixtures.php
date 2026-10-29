<?php

use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// Disposable synthetic integration database only. Never invoke against real data.
require __DIR__.'/../apps/edu-api/vendor/autoload.php';
$app = require __DIR__.'/../apps/edu-api/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.connections.'.config('database.default').'.database') !== 'edu_test') {
    throw new RuntimeException('Requires isolated edu_test database and APP_ENV=testing.');
}
Cache::flush();
if (($argv[1] ?? '') === 'expire') {
    DB::table('sessions')->update(['last_activity' => time() - 30000]);
    exit;
}
$command = $argv[1] ?? '';
$schoolA = School::where('code', 'A')->first();
$memberA = $schoolA ? Membership::where('school_id', $schoolA->id)->where('login_identifier', 'TEACHER')->first() : null;
if ($command === 'recovery-target' && $memberA) {
    echo $memberA->id;
    exit;
}
if ($memberA && in_array($command, ['disable-identity', 'disable-membership', 'suspend-school'], true)) {
    if ($command === 'disable-identity') {
        $memberA->user->update(['status' => 'inactive']);
    }
    if ($command === 'disable-membership') {
        $memberA->update(['status' => 'inactive']);
    }
    if ($command === 'suspend-school') {
        $schoolA->update(['access_state' => 'suspended']);
    }
    exit;
}
if ($memberA) {
    $memberA->user->update(['status' => 'active', 'password' => 'Synthetic-Browser-Only-Password']); // Restore only this disposable fixture after password-recovery tests.
    $memberA->update(['status' => 'active']);
    $schoolA->update(['access_state' => 'live_enabled']);
}
foreach (['A' => 'live_enabled', 'B' => 'live_enabled', 'S' => 'suspended'] as $code => $state) {
    $school = School::firstOrCreate(['code' => $code], ['name' => 'Synthetic '.$code, 'data_stage' => 'live', 'access_state' => $state, 'entitlement_ends_at' => now()->addDays(30)]);
    if (! Membership::where('school_id', $school->id)->exists()) {
        $user = User::create(['name' => 'Synthetic browser fixture', 'password' => 'Synthetic-Browser-Only-Password']);
        Membership::create(['school_id' => $school->id, 'user_id' => $user->id, 'role' => 'teacher', 'login_identifier' => 'TEACHER', 'status' => 'active']);
        $inactive = User::create(['name' => 'Synthetic inactive fixture', 'password' => 'Synthetic-Browser-Only-Password']);
        Membership::create(['school_id' => $school->id, 'user_id' => $inactive->id, 'role' => 'student', 'login_identifier' => 'INACTIVE', 'status' => 'inactive']);
    }
}

$school = School::where('code', 'A')->first();
if (! Membership::where('school_id', $school->id)->where('login_identifier', 'ADMIN')->exists()) {
    $admin = User::create(['name' => 'Synthetic activation admin', 'password' => 'Synthetic-Browser-Only-Password']);
    Membership::create(['school_id' => $school->id, 'user_id' => $admin->id, 'role' => 'school_admin', 'login_identifier' => 'ADMIN', 'status' => 'active']);
}

// Reset only the synthetic admin MFA fixture between integration tests.
if ($command === '') {
    $adminMember = Membership::where('school_id', $school->id)->where('login_identifier', 'ADMIN')->firstOrFail();
    DB::table('mfa_recovery_codes')->where('user_id', $adminMember->user_id)->delete();
    DB::table('mfa_credentials')->where('user_id', $adminMember->user_id)->delete();
}
