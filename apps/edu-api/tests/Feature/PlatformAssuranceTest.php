<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlatformAssurance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformAssuranceTest extends TestCase
{
    use RefreshDatabase;

    public function test_synthetic_assurance_is_current_context_bound_and_never_production_enabled(): void
    {
        $user = User::create(['name' => 'Synthetic platform', 'password' => 'Synthetic-Platform-Password']);
        $user->forceFill(['is_platform_admin' => true])->save();
        $version = (string) Str::ulid();
        DB::table('mfa_credentials')->insert(['user_id' => $user->id, 'secret_encrypted' => encrypt('synthetic-unused-seed'), 'version' => $version, 'confirmed_at' => now()]);
        $request = Request::create('https://localhost/api/v1/browser/platform/identity');
        $request->setUserResolver(fn () => $user);
        $session = new Store('synthetic', new ArraySessionHandler(480));
        $session->start();
        $request->setLaravelSession($session);
        $context = ['user_id' => $user->id, 'credential' => hash('sha256', $user->password), 'issued_at' => now()->timestamp, 'seen_at' => now()->timestamp];
        $assurance = ['context' => 'platform', 'user_id' => $user->id, 'version' => $version, 'session' => hash('sha256', $session->getId()), 'at' => now()->timestamp];
        $session->put(['platform_context' => $context, 'platform_assurance' => $assurance]);
        $policy = app(PlatformAssurance::class);
        $this->assertFalse($policy->allows($request, 'platform.health'));
        config(['platform.synthetic_auth' => true]);
        $this->assertTrue($policy->allows($request, 'platform.health'));
        $this->assertFalse($policy->allows($request, 'school.private'));
        foreach ([['at' => now()->subMinutes(15)->timestamp], ['at' => now()->addSecond()->timestamp], ['context' => 'school'], ['version' => 'stale'], ['user_id' => 'foreign'], ['session' => 'foreign']] as $change) {
            $session->put('platform_assurance', array_merge($assurance, $change));
            $this->assertFalse($policy->allows($request, 'platform.health'));
        }
        $session->put('platform_assurance', $assurance);
        $request->headers->set('Authorization', 'Bearer synthetic');
        $this->assertFalse($policy->allows($request, 'platform.health'));
        $request->headers->remove('Authorization');
        $session->put('school_id', 'foreign');
        $this->assertFalse($policy->allows($request, 'platform.health'));
        $session->forget('school_id');
        DB::table('session_revocations')->insert(['session_hash' => hash('sha256', $session->getId()), 'user_id' => $user->id, 'created_at' => now()]);
        $this->assertFalse($policy->allows($request, 'platform.health'));
        app()->instance('env', 'production');
        $this->assertFalse($policy->allows($request, 'platform.health'));
    }
}
