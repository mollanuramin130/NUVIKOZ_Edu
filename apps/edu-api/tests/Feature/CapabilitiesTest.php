<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Services\Capabilities;
use App\Services\MembershipSecurity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $code = 'A', string $role = 'school_admin'): Membership
    {
        $s = School::firstOrCreate(['code' => $code], ['name' => 'Synthetic', 'data_stage' => 'live', 'access_state' => 'live_enabled', 'entitlement_ends_at' => now()->addDay()]);
        $u = User::create(['name' => 'Synthetic', 'password' => 'Synthetic-Capability-Password']);

        return Membership::create(['school_id' => $s->id, 'user_id' => $u->id, 'role' => $role, 'status' => 'active', 'login_identifier' => $u->id]);
    }

    private function denied(callable $work): void
    {
        try {
            $work();
            $this->fail('Forbidden permission accepted');
        } catch (HttpException|ValidationException|AuthorizationException|ModelNotFoundException) {
            $this->assertTrue(true);
        }
    }

    public function test_allowed_delegation_revoke_and_stale_version_are_authoritative(): void
    {
        $a = $this->member();
        $t = $this->member('A', 'teacher');
        $this->mfaFixture($a);
        $service = app(Capabilities::class);
        $this->assertFalse($service->allows($t, 'marks.enter'));
        $service->assign($a, $t->id, ['capability' => 'marks.enter', 'allowed' => true, 'version' => 1], 'grant');
        $this->assertTrue($service->allows($t, 'marks.enter'));
        $this->denied(fn () => $service->assign($a, $t->id, ['capability' => 'marks.enter', 'allowed' => false, 'version' => 1], 'stale'));
        $service->assign($a, $t->id, ['capability' => 'marks.enter', 'allowed' => false, 'version' => 2], 'revoke');
        $this->assertFalse($service->allows($t, 'marks.enter'));
        $audit = AuditEvent::where('action', 'permission.revoked')->firstOrFail();
        $this->assertTrue($audit->details['before']);
        $this->assertFalse($audit->details['after']);
        $this->assertSame(3, $audit->details['to_version']);
    }

    public function test_unknown_fixed_powers_role_ceiling_and_cross_school_are_denied(): void
    {
        $a = $this->member();
        $p = $this->member('A', 'principal');
        $t = $this->member('A', 'teacher');
        $foreign = $this->member('B', 'principal');
        $this->mfaFixture($a);
        $service = app(Capabilities::class);
        foreach (['accounts.provision', 'accounts.activate', 'accounts.recover', 'platform.admin', 'permissions.manage', 'attendance.cutoff.approve', 'storage.quota.override', 'homework.upload', 'demo.upload', 'unknown'] as $cap) {
            $this->denied(fn () => $service->assign($a, $p->id, ['capability' => $cap, 'allowed' => true, 'version' => 1], 'denied'));
            $this->assertFalse($service->allows($p, $cap));
        }
        $this->denied(fn () => $service->assign($a, $t->id, ['capability' => 'financial.export', 'allowed' => true, 'version' => 1], 'ceiling'));
        $this->denied(fn () => $service->assign($a, $foreign->id, ['capability' => 'financial.export', 'allowed' => true, 'version' => 1], 'foreign'));
        $this->assertDatabaseCount('membership_permissions', 0);
    }

    public function test_principal_teacher_and_family_cannot_self_delegate(): void
    {
        $target = $this->member('A', 'teacher');
        foreach (['principal', 'teacher', 'student', 'parent'] as $role) {
            $actor = $this->member('A', $role);
            $this->denied(fn () => app(Capabilities::class)->assign($actor, $target->id, ['capability' => 'marks.enter', 'allowed' => true, 'version' => 1], 'no-authority'));
        }$this->assertDatabaseCount('membership_permissions', 0);
    }

    public function test_missing_mfa_inactive_target_and_expired_entitlement_cannot_mutate(): void
    {
        $a = $this->member();
        $p = $this->member('A', 'principal');
        $data = ['capability' => 'financial.export', 'allowed' => true, 'version' => 1];
        $this->denied(fn () => app(Capabilities::class)->assign($a, $p->id, $data, 'no-mfa'));
        $this->mfaFixture($a);
        $p->update(['status' => 'inactive']);
        $this->denied(fn () => app(Capabilities::class)->assign($a, $p->id, $data, 'inactive'));
        $p->update(['status' => 'active']);
        $a->school->update(['entitlement_ends_at' => now()->subDay()]);
        $this->denied(fn () => app(Capabilities::class)->assign($a, $p->id, array_merge($data, ['version' => 3]), 'expired'));
        $this->assertDatabaseCount('membership_permissions', 0);
    }

    public function test_role_ceiling_is_current_even_with_a_preexisting_permission_row(): void
    {
        $a = $this->member();
        $p = $this->member('A', 'principal');
        $this->mfaFixture($a);
        app(Capabilities::class)->assign($a, $p->id, ['capability' => 'financial.export', 'allowed' => true, 'version' => 1], 'grant');
        $this->assertTrue(app(Capabilities::class)->allows($p, 'financial.export'));
        $p->update(['role' => 'teacher']);
        $this->assertFalse(app(Capabilities::class)->allows($p, 'financial.export'));
        $p->school->update(['access_state' => 'suspended']);
        $this->denied(fn () => app(Capabilities::class)->allows($p, 'financial.export'));
    }

    public function test_last_active_admin_demotion_deactivation_and_deletion_are_denied(): void
    {
        $a = $this->member();
        $inactive = $this->member();
        $inactive->user->update(['activation_required' => true]);
        foreach ([['role' => 'principal'], ['status' => 'inactive']] as $data) {
            $a->refresh();
            $this->denied(fn () => $a->update($data));
            $this->assertSame('school_admin', $a->fresh()->role->value);
            $this->assertSame('active', $a->fresh()->status);
        }
        $this->denied(fn () => $a->delete());
        $this->assertDatabaseHas('memberships', ['id' => $a->id]);
    }

    public function test_authorized_member_mutation_is_versioned_audited_and_revokes_stale_authority(): void
    {
        $a = $this->member();
        $other = $this->member();
        $this->mfaFixture($a);
        $other->user->createToken('Old', ['school:'.$a->school_id], now()->addDay());
        app(MembershipSecurity::class)->change($a, $other->id, ['role' => 'principal', 'version' => 1], 'demote');
        $this->assertSame('principal', $other->fresh()->role->value);
        $this->assertSame(0, $other->user->tokens()->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'membership.security_changed']);
        $this->denied(fn () => app(MembershipSecurity::class)->change($a, $other->id, ['status' => 'inactive', 'version' => 1], 'stale'));
        $this->denied(fn () => app(MembershipSecurity::class)->change($a, $other->id, ['role' => 'school_admin', 'version' => 2], 'escalation'));
    }

    public function test_audit_failure_rolls_back_grant_and_version(): void
    {
        $a = $this->member();
        $t = $this->member('A', 'teacher');
        $this->mfaFixture($a);
        AuditEvent::creating(fn () => throw new \RuntimeException('Synthetic audit failure'));
        try {
            app(Capabilities::class)->assign($a, $t->id, ['capability' => 'marks.enter', 'allowed' => true, 'version' => 1], 'rollback');
            $this->fail('Expected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic audit failure', $e->getMessage());
        }
        $this->assertDatabaseCount('membership_permissions', 0);
        $this->assertSame(1, (int) $t->fresh()->permission_version);
    }

    public function test_demo_file_guardrails_cannot_be_overridden_by_delegation(): void
    {
        $a = $this->member();
        $p = $this->member('A', 'principal');
        $this->mfaFixture($a);
        app(Capabilities::class)->assign($a, $p->id, ['capability' => 'financial.export', 'allowed' => true, 'version' => 1], 'grant');
        $p->school->update(['data_stage' => 'demo', 'demo_ends_at' => now()->addDay()]);
        $this->assertFalse(app(Capabilities::class)->allows($p, 'financial.export'));
        $this->assertFalse(app(Capabilities::class)->allows($a, 'certificate.issue'));
    }
}
