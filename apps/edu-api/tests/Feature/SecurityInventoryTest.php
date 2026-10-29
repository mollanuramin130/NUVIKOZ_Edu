<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Services\SecurityInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SecurityInventoryTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $code = 'A'): Membership
    {
        $s = School::firstOrCreate(['code' => $code], ['name' => 'Synthetic', 'data_stage' => 'live', 'access_state' => 'live_enabled', 'entitlement_ends_at' => now()->addDay()]);
        $u = User::create(['name' => 'Synthetic', 'password' => 'Synthetic-Device-Password']);

        return Membership::create(['school_id' => $s->id, 'user_id' => $u->id, 'role' => 'teacher', 'login_identifier' => $u->id, 'status' => 'active']);
    }

    private function browser(Membership $m): void
    {
        $session = app('session')->driver();
        $session->start();
        request()->setLaravelSession($session);
        Auth::guard('web')->login($m->user);
        request()->setUserResolver(fn () => $m->user);
        $session->put(['issued_at' => time(), 'seen_at' => time(), 'school_id' => $m->school_id, 'credential_hash' => hash('sha256', $m->user->password)]);
    }

    public function test_actual_owned_session_inventory_excludes_payload_and_foreign_identity(): void
    {
        $m = $this->member();
        $other = $this->member('B');
        $this->browser($m);
        foreach ([request()->session()->getId(), 'other-owned'] as $id) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $m->user_id, 'payload' => 'sensitive', 'last_activity' => time()]);
        }
        DB::table('sessions')->insert(['id' => 'foreign', 'user_id' => $other->user_id, 'payload' => 'private', 'last_activity' => time()]);
        $rows = app(SecurityInventory::class)->sessions($m, request());
        $this->assertCount(2, $rows);
        $this->assertCount(1, array_filter($rows, fn ($r) => $r['current']));
        $this->assertStringNotContainsString('sensitive', json_encode($rows));
        $this->assertStringNotContainsString('other-owned', json_encode($rows));
        $id = app(SecurityInventory::class)->opaque('session', 'other-owned', $m->user_id);
        $this->assertSame(1, app(SecurityInventory::class)->revoke($m, request(), 'Synthetic-Device-Password', 'session', $id)['revoked']);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-owned']);
        // A late session writer may recreate the row, but cannot remove the revocation tombstone.
        DB::table('sessions')->insert(['id' => 'other-owned', 'user_id' => $m->user_id, 'payload' => 'late', 'last_activity' => time()]);
        $this->assertDatabaseHas('session_revocations', ['session_hash' => hash('sha256', 'other-owned')]);
        $this->assertCount(1, app(SecurityInventory::class)->sessions($m, request()));
    }

    public function test_device_inventory_and_revocation_are_owner_and_school_scoped(): void
    {
        $m = $this->member();
        $other = $this->member('B');
        $this->browser($m);
        $mine = $m->user->createToken('Synthetic phone', ['school:'.$m->school_id], now()->addDays(7));
        $foreign = $other->user->createToken('Foreign', ['school:'.$other->school_id], now()->addDays(7));
        $different = $m->user->createToken('Other school', ['school:'.$other->school_id], now()->addDays(7));
        $rows = app(SecurityInventory::class)->devices($m, request());
        $this->assertCount(1, $rows);
        $this->assertStringNotContainsString($mine->plainTextToken, json_encode($rows));
        try {
            app(SecurityInventory::class)->revoke($m, request(), 'Synthetic-Device-Password', 'device', app(SecurityInventory::class)->opaque('device', (string) $foreign->accessToken->id, $m->user_id));
            $this->fail('Foreign revoke');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertSame(1, app(SecurityInventory::class)->revoke($m, request(), 'Synthetic-Device-Password', 'device', $rows[0]['id'])['revoked']);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $foreign->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $different->accessToken->id]);
    }

    public function test_wrong_password_and_stale_identity_never_revoke(): void
    {
        $m = $this->member();
        $this->browser($m);
        $m->user->createToken('Synthetic', ['school:'.$m->school_id], now()->addDay());
        try {
            app(SecurityInventory::class)->revoke($m, request(), 'wrong', 'device', 'others');
            $this->fail('No reauthentication');
        } catch (ValidationException) {
            $this->assertDatabaseCount('personal_access_tokens', 1);
        }
        $m->update(['status' => 'inactive']);
        $this->expectException(HttpException::class);
        app(SecurityInventory::class)->devices($m, request());
    }

    public function test_security_mutation_revokes_browser_context_and_only_affected_school_devices(): void
    {
        $m = $this->member();
        $other = $this->member('B');
        $a = $m->user->createToken('A', ['school:'.$m->school_id], now()->addDay());
        $b = $m->user->createToken('B', ['school:'.$other->school_id], now()->addDay());
        DB::table('sessions')->insert(['id' => 'synthetic-old', 'user_id' => $m->user_id, 'payload' => '', 'last_activity' => time()]);
        $m->update(['role' => 'principal']);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $a->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $b->accessToken->id]);
        $this->assertDatabaseHas('session_revocations', ['session_hash' => hash('sha256', 'synthetic-old')]);
        $this->assertDatabaseMissing('sessions', ['id' => 'synthetic-old']);
    }

    public function test_revoke_other_sessions_preserves_current_and_foreign_users(): void
    {
        $m = $this->member();
        $other = $this->member('B');
        $this->browser($m);
        foreach ([[request()->session()->getId(), $m->user_id], ['other-owned', $m->user_id], ['foreign', $other->user_id]] as [$id, $user]) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $user, 'payload' => '', 'last_activity' => time()]);
        }
        $this->assertSame(1, app(SecurityInventory::class)->revoke($m, request(), 'Synthetic-Device-Password', 'session', 'others')['revoked']);
        $this->assertDatabaseHas('sessions', ['id' => request()->session()->getId()]);
        $this->assertDatabaseHas('sessions', ['id' => 'foreign']);
        $this->assertDatabaseMissing('sessions', ['id' => 'other-owned']);
    }

    public function test_active_device_inventory_excludes_expired_and_unbounded_tokens(): void
    {
        $m = $this->member();
        $this->browser($m);
        $m->user->createToken('Expired', ['school:'.$m->school_id], now()->subSecond());
        $m->user->createToken('Unbounded', ['school:'.$m->school_id]);
        $m->user->createToken('Active', ['school:'.$m->school_id], now()->addDay());
        $rows = app(SecurityInventory::class)->devices($m, request());
        $this->assertCount(1, $rows);
        $this->assertSame('Active', $rows[0]['name']);
    }

    public function test_failed_revocation_audit_rolls_back_token_and_session_changes(): void
    {
        $m = $this->member();
        $this->browser($m);
        $token = $m->user->createToken('Active', ['school:'.$m->school_id], now()->addDay());
        AuditEvent::creating(fn () => throw new \RuntimeException('Synthetic audit failure'));
        try {
            app(SecurityInventory::class)->revoke($m, request(), 'Synthetic-Device-Password', 'device', app(SecurityInventory::class)->opaque('device', (string) $token->accessToken->id, $m->user_id));
            $this->fail('Expected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic audit failure', $e->getMessage());
        }
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->accessToken->id]);
    }
}
