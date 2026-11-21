<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Services\AccountActivation;
use App\Services\InitialSchoolAdmin;
use App\Services\SchoolAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InitialSchoolAdminTest extends TestCase
{
    use RefreshDatabase;

    private function approval(): array
    {
        $code = 'EDU-STAGE-TEST';
        $id = (string) Str::ulid();
        $secret = bin2hex(random_bytes(32));
        $password = bin2hex(random_bytes(24));
        $manifest = json_encode([
            'school_code' => $code,
            'proof_id' => $id,
            'secret_hash' => hash('sha256', $secret),
            'expires_at' => now()->addMinutes(15)->timestamp,
        ], JSON_THROW_ON_ERROR);
        config(['staging-bootstrap.approval_digest' => hash('sha256', $manifest)]);

        return [$manifest, ['school_code' => $code, 'proof_id' => $id, 'secret' => $secret, 'password' => $password, 'password_confirmation' => $password]];
    }

    public function test_approved_bootstrap_requires_activation_then_mfa_and_is_single_use(): void
    {
        [$manifest, $data] = $this->approval();
        app(InitialSchoolAdmin::class)->initialize($manifest);
        $member = Membership::sole();
        $this->assertTrue($member->user->activation_required);
        $this->assertTrue($member->user->must_change_password);
        $this->assertFalse($member->user->is_platform_admin);
        $this->assertSame('inactive', User::whereKey(AuditEvent::sole()->actor_user_id)->sole()->status);
        $proof = DB::table('initial_school_admin_proofs')->first();
        $this->assertSame(hash('sha256', $data['secret']), $proof->secret_hash);
        $this->assertStringNotContainsString($data['secret'], AuditEvent::all()->toJson());
        $this->assertFalse(Hash::check($data['password'], $member->user->password));

        $result = app(AccountActivation::class)->consume($data, 'test-activation');
        $this->assertSame(['activated' => true, 'login_required' => true], $result);
        $this->assertTrue(Hash::check($data['password'], $member->user->fresh()->password));
        $this->assertFalse($member->user->fresh()->activation_required);
        $this->assertFalse($member->user->fresh()->must_change_password);
        $this->assertDatabaseCount('mfa_credentials', 0);
        $this->assertDatabaseHas('audit_events', ['action' => 'staging.initial_admin.activated', 'actor_user_id' => $member->user_id]);
        try {
            app(SchoolAccess::class)->requireAdmin($member);
            $this->fail('Privileged access must require MFA.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $this->expectException(ValidationException::class);
        app(AccountActivation::class)->consume($data, 'test-replay');
    }

    public function test_default_disabled_and_tampered_manifests_cannot_initialize(): void
    {
        [$manifest] = $this->approval();
        foreach ([$manifest.' ', $manifest] as $index => $input) {
            if ($index === 1) {
                config(['staging-bootstrap.approval_digest' => null]);
            }
            try {
                app(InitialSchoolAdmin::class)->initialize($input);
                $this->fail('Unapproved manifest was accepted.');
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('schools', 0);
            }
        }
    }

    public function test_duplicate_school_and_expired_or_cross_tenant_proofs_are_rejected(): void
    {
        [$manifest, $data] = $this->approval();
        app(InitialSchoolAdmin::class)->initialize($manifest);
        $second = json_encode(array_replace(json_decode($manifest, true, flags: JSON_THROW_ON_ERROR), ['proof_id' => (string) Str::ulid()]), JSON_THROW_ON_ERROR);
        config(['staging-bootstrap.approval_digest' => hash('sha256', $second)]);
        try {
            app(InitialSchoolAdmin::class)->initialize($second);
            $this->fail('A second initialization was accepted.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('memberships', 1);
        }

        School::create(['code' => 'EDU-STAGE-OTHER', 'name' => 'Other synthetic school']);
        foreach ([array_replace($data, ['school_code' => 'EDU-STAGE-OTHER']), array_replace($data, ['secret' => str_repeat('0', 64)])] as $invalid) {
            try {
                app(AccountActivation::class)->consume($invalid, 'test-denied');
                $this->fail('Invalid proof was accepted.');
            } catch (ValidationException) {
                $this->assertNull(DB::table('initial_school_admin_proofs')->value('consumed_at'));
            }
        }
        $this->travel(16)->minutes();
        $this->expectException(ValidationException::class);
        app(AccountActivation::class)->consume($data, 'test-expired');
    }

    public function test_expired_approval_cannot_create_a_school(): void
    {
        [$manifest] = $this->approval();
        $this->travel(16)->minutes();
        try {
            app(InitialSchoolAdmin::class)->initialize($manifest);
            $this->fail('Expired approval was accepted.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('schools', 0);
        }
    }

    public function test_audit_write_failure_rolls_back_issuance_and_activation(): void
    {
        [$manifest, $data] = $this->approval();
        $failAction = 'staging.initial_admin.issued';
        AuditEvent::creating(function (AuditEvent $event) use (&$failAction): void {
            if ($event->action === $failAction) {
                throw new RuntimeException('Synthetic audit failure');
            }
        });
        try {
            app(InitialSchoolAdmin::class)->initialize($manifest);
            $this->fail('Issuance committed without an audit.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic audit failure', $error->getMessage());
            $this->assertDatabaseCount('schools', 0);
            $this->assertDatabaseCount('initial_school_admin_proofs', 0);
        }

        $failAction = 'staging.initial_admin.activated';
        app(InitialSchoolAdmin::class)->initialize($manifest);
        try {
            app(AccountActivation::class)->consume($data, 'test-audit-failure');
            $this->fail('Activation committed without an audit.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic audit failure', $error->getMessage());
            $this->assertTrue(Membership::sole()->user->activation_required);
            $this->assertNull(DB::table('initial_school_admin_proofs')->value('consumed_at'));
            $this->assertDatabaseCount('audit_events', 1);
        }
    }
}
