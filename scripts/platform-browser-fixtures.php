<?php

use App\Models\User;
use App\Services\SecurityRevocation;
use App\Services\Totp;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../apps/edu-api/vendor/autoload.php';
$app = require __DIR__.'/../apps/edu-api/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (PHP_SAPI !== 'cli' || ! app()->environment('testing') || config('database.connections.'.config('database.default').'.database') !== 'edu_test') {
    throw new RuntimeException('Disposable edu_test fixture only.');
}
$user = User::where('name', 'Synthetic platform browser fixture')->first();
$command = $argv[1] ?? 'reset';
if ($command === 'expire-assurance') {
    foreach (DB::table('sessions')->where('user_id', $user?->id)->get() as $row) {
        $data = json_decode(Crypt::decrypt(base64_decode($row->payload)), true, flags: JSON_THROW_ON_ERROR);
        if (isset($data['platform_assurance'])) {
            $data['platform_assurance']['at'] = now()->subMinutes(16)->timestamp;
            DB::table('sessions')->where('id', $row->id)->update(['payload' => base64_encode(Crypt::encrypt(json_encode($data, JSON_THROW_ON_ERROR)))]);
        }
    }
    exit;
}
if ($command === 'seed') {
    echo Crypt::decryptString(DB::table('mfa_credentials')->where('user_id', $user->id)->value('secret_encrypted'));
    exit;
}
if ($command === 'reset') {
    Cache::flush();
    $user ??= User::create(['name' => 'Synthetic platform browser fixture', 'password' => 'Synthetic-Platform-Password']);
    $user->forceFill(['password' => 'Synthetic-Platform-Password', 'status' => 'active', 'is_platform_admin' => true])->save();
    app(SecurityRevocation::class)->user($user->id);
    DB::table('mfa_recovery_codes')->where('user_id', $user->id)->delete();
    DB::table('mfa_credentials')->where('user_id', $user->id)->delete();
}
if ($command === 'enrolled') {
    DB::table('mfa_credentials')->insert(['user_id' => $user->id, 'secret_encrypted' => Crypt::encryptString(app(Totp::class)->seed()), 'version' => (string) Str::ulid(), 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
}
echo $user->id;
