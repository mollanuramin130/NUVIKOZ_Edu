<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlatformFirstFactor;
use App\Services\PlatformMfa;
use App\Services\Totp;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlatformMfaRollbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_failure_rolls_back_enrollment_confirmation_and_recovery_consumption(): void
    {
        config(['platform.synthetic_auth' => true]);
        $u = User::create(['name' => 'Synthetic', 'password' => 'Synthetic-Platform-Password']);
        $u->forceFill(['is_platform_admin' => true])->save();
        $r = Request::create('https://localhost/api/v1/browser/platform/mfa');
        $session = new Store('synthetic', new ArraySessionHandler(480));
        $session->start();
        $r->setLaravelSession($session);
        app(PlatformFirstFactor::class)->execute($r, ['identifier' => $u->id, 'password' => 'Synthetic-Platform-Password']);
        $failAudit = true;
        DB::listen(function (QueryExecuted $query) use (&$failAudit) {
            if ($failAudit && str_starts_with(strtolower($query->sql), 'insert into') && str_contains($query->sql, 'platform_security_audits')) {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        $service = app(PlatformMfa::class);
        $id = $session->getId();
        $expectFailure = function (callable $command) {
            try {
                $command();
                $this->fail('Audit failure must abort security mutation');
            } catch (\RuntimeException $e) {
                $this->assertSame('Synthetic audit failure', $e->getMessage());
            }
        };
        $expectFailure(fn () => $service->enroll($r, 'Synthetic-Platform-Password'));
        $this->assertDatabaseCount('mfa_credentials', 0);
        $this->assertDatabaseCount('platform_security_audits', 1);
        $this->assertSame($id, $session->getId());
        $failAudit = false;
        $setup = $service->enroll($r, 'Synthetic-Platform-Password');
        $code = app(Totp::class)->code($setup['secret'], now()->timestamp);
        $before = (array) DB::table('mfa_credentials')->first();
        $failAudit = true;
        $expectFailure(fn () => $service->verify($r, 'Synthetic-Platform-Password', $code, 'confirm'));
        $this->assertSame($before, (array) DB::table('mfa_credentials')->first());
        $this->assertDatabaseCount('mfa_recovery_codes', 0);
        $this->assertFalse($session->has('platform_assurance'));
        $failAudit = false;
        $result = $service->verify($r, 'Synthetic-Platform-Password', $code, 'confirm');
        $recovery = $result['recovery_codes'][0];
        $id = $session->getId();
        $proof = $session->get('platform_assurance');
        $count = DB::table('platform_security_audits')->count();
        $failAudit = true;
        $expectFailure(fn () => $service->verify($r, 'Synthetic-Platform-Password', $recovery, 'recovery'));
        $this->assertNull(DB::table('mfa_recovery_codes')->where('code_hash', hash('sha256', $recovery))->value('consumed_at'));
        $this->assertSame($count, DB::table('platform_security_audits')->count());
        $this->assertSame($id, $session->getId());
        $this->assertSame($proof, $session->get('platform_assurance'));
        $failAudit = false;
    }
}
