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
if ($schoolA && $command === 'academic-ui-expire') {
    $schoolA->update(['entitlement_ends_at' => now()->subMinute()]);
    exit;
}
if ($schoolA && $command === 'academic-ui-pagination') {
    for ($i = 0; $i < 26; $i++) {
        DB::table('classes')->insertOrIgnore(['id' => (string) \Illuminate\Support\Str::ulid(), 'school_id' => $schoolA->id, 'name' => sprintf('Pagination Class %02d', $i), 'sort_order' => $i, 'created_at' => now(), 'updated_at' => now()]);
    }
    exit;
}
if ($schoolA && $command === 'academic-ui-revoke') {
    $admin = Membership::where('school_id', $schoolA->id)->where('login_identifier', 'ADMIN')->firstOrFail();
    $backup = User::create(['name' => 'Synthetic backup admin', 'password' => 'Synthetic-Backup-Password']);
    Membership::create(['school_id' => $schoolA->id, 'user_id' => $backup->id, 'role' => 'school_admin', 'status' => 'active', 'login_identifier' => $backup->id]);
    $admin->update(['status' => 'inactive']);
    exit;
}

if (in_array($command, ['recovery-target', 'permission-target'], true) && $memberA) {
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
    $schoolA->update(['access_state' => 'live_enabled', 'entitlement_ends_at' => now()->addDays(30)]);
}
foreach (['A' => 'live_enabled', 'B' => 'live_enabled', 'S' => 'suspended'] as $code => $state) {
    $school = School::firstOrCreate(['code' => $code], ['settings' => ['timezone' => 'Asia/Kolkata'], 'name' => 'Synthetic '.$code, 'data_stage' => 'live', 'access_state' => $state, 'entitlement_ends_at' => now()->addDays(30)]);
    if (! Membership::where('school_id', $school->id)->exists()) {
        $user = User::create(['name' => 'Synthetic browser fixture', 'password' => 'Synthetic-Browser-Only-Password']);
        Membership::create(['school_id' => $school->id, 'user_id' => $user->id, 'role' => 'teacher', 'login_identifier' => 'TEACHER', 'status' => 'active']);
        $inactive = User::create(['name' => 'Synthetic inactive fixture', 'password' => 'Synthetic-Browser-Only-Password']);
        Membership::create(['school_id' => $school->id, 'user_id' => $inactive->id, 'role' => 'student', 'login_identifier' => 'INACTIVE', 'status' => 'inactive']);
    }
}

// Synthetic admins in two schools enable real cross-tenant academic integration tests.
foreach (['A', 'B'] as $code) {
    $school = School::where('code', $code)->firstOrFail();
    if (! Membership::where('school_id', $school->id)->where('login_identifier', 'ADMIN')->exists()) {
        $admin = User::create(['name' => 'Synthetic activation admin', 'password' => 'Synthetic-Browser-Only-Password']);
        Membership::create(['school_id' => $school->id, 'user_id' => $admin->id, 'role' => 'school_admin', 'login_identifier' => 'ADMIN', 'status' => 'active']);
    }
    if ($command === '') {
        $adminMember = Membership::where('school_id', $school->id)->where('login_identifier', 'ADMIN')->firstOrFail();
        $adminMember->update(['status' => 'active']);
        DB::table('mfa_recovery_codes')->where('user_id', $adminMember->user_id)->delete();
        DB::table('mfa_credentials')->where('user_id', $adminMember->user_id)->delete();
    }
}
