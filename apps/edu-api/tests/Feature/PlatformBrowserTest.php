<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Services\ProvisionedSchoolAdminActivation;
use App\Services\SchoolEntitlements;
use App\Services\Totp;
use App\Services\VerifiedSchoolAdminDelivery;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PlatformBrowserTest extends TestCase
{
    use RefreshDatabase;

    private string $cookie = '';

    private array $staleCookies = [];

    private string $csrf = '';

    private function browser(string $method, string $path, array $data = []): TestResponse
    {
        // A new HTTP request must load only persisted state, never the previous store/guard.
        app('auth')->forgetGuards();
        app('auth')->shouldUse('web');
        app()->forgetInstance('auth.driver');
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        $this->withCredentials()->withServerVariables(['HTTPS' => 'on', 'SERVER_PORT' => 443])
            ->withHeader('Origin', 'https://web.edu.test')->withHeader('X-CSRF-TOKEN', $this->csrf)
            ->withUnencryptedCookie('edu_browser_session', $this->cookie);
        $response = $this->json($method, 'https://localhost/api/v1/browser/'.$path, $data);
        if ($cookie = $response->getCookie('edu_browser_session', false)) {
            $this->cookie = $cookie->getValue();
        }
        if ($token = $response->json('data.csrf_token')) {
            $this->csrf = $token;
        }

        return $response;
    }

    private function sessionData(): array
    {
        $id = app('session')->driver()->getId();
        $payload = DB::table('sessions')->where('id', $id)->value('payload');

        return json_decode(Crypt::decrypt(base64_decode($payload)), true, flags: JSON_THROW_ON_ERROR);
    }

    private function saveSession(array $data): void
    {
        DB::table('sessions')->where('id', app('session')->driver()->getId())
            ->update(['payload' => base64_encode(Crypt::encrypt(json_encode($data, JSON_THROW_ON_ERROR)))]);
    }

    private function authenticate(): User
    {
        config(['platform.synthetic_auth' => true, 'browser.origins' => ['https://web.edu.test']]);
        $u = User::create(['name' => 'Synthetic', 'password' => 'Synthetic-Platform-Password']);
        $u->forceFill(['is_platform_admin' => true])->save();
        $this->browser('GET', 'csrf')->assertOk();
        $this->staleCookies[] = $this->cookie;
        $bootstrap = app('session')->driver()->getId();
        $this->browser('POST', 'platform/login', ['identifier' => $u->id, 'password' => 'Synthetic-Platform-Password'])
            ->assertOk()->assertJsonPath('data.mfa_required', true);
        $this->staleCookies[] = $this->cookie;
        $pending = app('session')->driver()->getId();
        $this->assertNotSame($bootstrap, $pending);
        $this->assertDatabaseMissing('sessions', ['id' => $bootstrap]);
        $this->browser('GET', 'platform/identity')->assertForbidden();
        $this->browser('GET', 'platform/registrations')->assertForbidden();
        $setup = $this->browser('POST', 'platform/mfa/enrollment', ['password' => 'Synthetic-Platform-Password'])->assertOk();
        $code = app(Totp::class)->code($setup->json('data.secret'), now()->timestamp);
        $this->browser('POST', 'platform/mfa/confirm', ['password' => 'Synthetic-Platform-Password', 'code' => $code])
            ->assertOk()->assertJsonPath('data.verified', true);
        $this->assertNotSame($pending, app('session')->driver()->getId());
        $this->assertDatabaseMissing('sessions', ['id' => $pending]);
        $this->assertDatabaseHas('session_revocations', ['session_hash' => hash('sha256', $pending)]);

        return $u;
    }

    public function test_registration_review_requires_platform_mfa_and_only_changes_the_application(): void
    {
        config(['platform.synthetic_auth' => true, 'browser.origins' => ['https://web.edu.test']]);
        $this->browser('GET', 'platform/registrations')->assertUnauthorized();
        $reviewer = $this->authenticate();
        config([
            'registration.enabled' => true,
            'registration.notice_version' => 'test-v1',
            'registration.notice_text' => 'Synthetic test privacy notice.',
        ]);
        $this->browser('GET', 'registration/notice')->assertOk()->assertJsonPath('data.notice_version', 'test-v1');
        $input = [
            'organization_name' => 'Synthetic Horizon', 'category' => 'school',
            'contact_name' => 'Synthetic Applicant', 'contact' => 'test@example.test',
            'notice_version' => 'test-v1',
        ];
        $this->browser('POST', 'registration/submit', $input)->assertUnprocessable();
        $input['notice_acknowledged'] = true;
        $application = $this->browser('POST', 'registration/submit', $input)->assertCreated();
        $id = $application->json('data.id');
        $token = $application->json('data.tracking_token');
        $this->browser('POST', 'registration/submit', $input)->assertConflict();
        $this->browser('GET', 'platform/registrations?search=Horizon&state=submitted')
            ->assertOk()->assertJsonPath('data.items.0.id', $id)->assertJsonMissingPath('data.items.0.contact');
        $this->browser('GET', 'platform/registrations?search=%25%25')->assertOk()->assertJsonCount(0, 'data.items');
        $detail = "platform/registrations/{$id}";
        $this->browser('GET', $detail)->assertOk()->assertJsonPath('data.registration.contact', 'test@example.test');
        $command = ['state' => 'approved', 'note' => 'Reviewed', 'command_id' => (string) Str::uuid()];
        $this->browser('POST', $detail.'/review', $command)->assertConflict();
        $this->browser('POST', $detail.'/provision', ['command_id' => (string) Str::uuid()])->assertConflict();
        $start = ['state' => 'reviewing', 'note' => 'Start review', 'command_id' => (string) Str::uuid()];
        $this->browser('POST', $detail.'/review', $start)->assertOk()->assertJsonPath('data.replayed', false);
        $this->browser('POST', $detail.'/review', $start)->assertOk()->assertJsonPath('data.replayed', true);
        $this->browser('POST', $detail.'/review', $command)->assertOk()->assertJsonPath('data.state', 'approved');
        $this->browser('POST', $detail.'/review', array_replace($command, ['note' => 'Tampered replay']))->assertConflict();
        $this->browser('GET', $detail)->assertOk()->assertJsonCount(2, 'data.history')
            ->assertJsonPath('data.history.1.actor_user_id', $reviewer->id);
        $this->browser('POST', 'registration/status', ['tracking_token' => $token])
            ->assertOk()->assertJsonPath('data.state', 'approved')
            ->assertJsonMissingPath('data.contact')->assertJsonMissingPath('data.tracking_token');
        $this->assertDatabaseCount('schools', 0);
        $this->assertDatabaseCount('memberships', 0);
        foreach (['update', 'delete'] as $operation) {
            try {
                $query = DB::table('registration_reviews')->where('registration_id', $id);
                $operation === 'update' ? $query->update(['note' => 'Changed']) : $query->delete();
                $this->fail('Review history was mutable.');
            } catch (QueryException $error) {
                $this->assertStringContainsString('Registration review is append-only', $error->getMessage());
            }
        }
        try {
            (require database_path('migrations/2026_11_22_000011_create_registration_reviews.php'))->down();
            $this->fail('Rollback discarded existing review history.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('preservation plan', $error->getMessage());
        }
        $this->assertDatabaseCount('registration_reviews', 2);
        $provision = ['command_id' => (string) Str::uuid()];
        $created = $this->browser('POST', $detail.'/provision', $provision)->assertCreated()
            ->assertJsonPath('data.provisioning.activation_state', 'awaiting_verified_delivery')
            ->assertJsonPath('data.replayed', false)->assertJsonMissingPath('data.provisioning.secret');
        $school = $created->json('data.provisioning.school_id');
        $this->browser('POST', $detail.'/provision', $provision)->assertOk()
            ->assertJsonPath('data.replayed', true)->assertJsonPath('data.provisioning.school_id', $school);
        $this->browser('POST', $detail.'/provision', ['command_id' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('schools', 1);
        $this->assertDatabaseCount('school_provisionings', 1);
        $this->assertDatabaseCount('account_activations', 0);
        $this->assertDatabaseCount('initial_school_admin_proofs', 0);
        $this->assertDatabaseHas('schools', ['id' => $school, 'access_state' => 'suspended', 'demo_ends_at' => null, 'entitlement_ends_at' => null]);
        $this->assertDatabaseHas('memberships', ['school_id' => $school, 'role' => 'school_admin', 'status' => 'inactive']);
        $this->assertDatabaseHas('audit_events', ['school_id' => $school, 'action' => 'platform.registration.provisioned']);
        $this->assertDatabaseCount('audit_events', 1);
        $grantDemo = ['command_id' => (string) Str::uuid(), 'action' => 'grant_demo'];
        $this->browser('POST', $detail.'/entitlement', $grantDemo)->assertConflict();
        $issue = ['command_id' => (string) Str::uuid()];
        $this->browser('POST', $detail.'/activation/issue', $issue)->assertStatus(503);
        $this->assertDatabaseCount('school_admin_activation_proofs', 0);

        $adapter = new class implements VerifiedSchoolAdminDelivery
        {
            public bool $verified = false;

            public array $proof = [];

            public function recipient(string $registrationId, string $submittedContact): ?string
            {
                return $this->verified ? 'synthetic-verified-recipient' : null;
            }

            public function deliver(string $recipient, array $proof): bool
            {
                $this->proof = $proof;

                return true;
            }
        };
        app()->instance(VerifiedSchoolAdminDelivery::class, $adapter);
        $this->browser('POST', $detail.'/activation/issue', $issue)->assertConflict();
        $adapter->verified = true;
        $this->browser('POST', $detail.'/activation/issue', $issue)->assertOk()->assertJsonPath('data.activation.state', 'pending_delivery')
            ->assertJsonMissingPath('data.activation.history.0.delivery_envelope');
        $this->browser('POST', $detail.'/activation/issue', $issue)->assertOk()->assertJsonPath('data.replayed', true);
        $service = app(ProvisionedSchoolAdminActivation::class);
        $this->assertTrue($service->deliver($id));
        $this->assertFalse($service->deliver($id));
        $revoked = $adapter->proof;
        $this->browser('POST', $detail.'/activation/revoke', ['command_id' => (string) Str::uuid()])->assertOk()
            ->assertJsonPath('data.activation.state', 'revoked');
        $reissue = ['command_id' => (string) Str::uuid()];
        $this->browser('POST', $detail.'/activation/reissue', $reissue)->assertOk()->assertJsonPath('data.activation.state', 'pending_delivery');
        DB::table('school_admin_activation_proofs')->where('registration_id', $id)->where('state', 'pending_delivery')
            ->update(['expires_at' => now()->subSecond()]);
        $service->expire();
        $this->assertDatabaseHas('school_admin_activation_proofs', ['state' => 'expired', 'delivery_envelope' => null, 'secret_hash' => null]);
        $this->assertFalse($service->deliver($id));
        $this->browser('POST', $detail.'/activation/reissue', ['command_id' => (string) Str::uuid()])->assertOk();
        $this->assertTrue($service->deliver($id));
        $this->assertDatabaseHas('school_admin_activation_proofs', ['id' => $adapter->proof['proof_id'], 'delivery_envelope' => null]);
        $this->browser('POST', 'platform/logout')->assertOk();
        $payload = fn (array $proof) => array_diff_key($proof, ['expires_at' => true]) + [
            'password' => 'Synthetic-passphrase-for-test-only',
            'password_confirmation' => 'Synthetic-passphrase-for-test-only',
        ];
        $this->browser('POST', 'activation/consume', $payload($revoked))->assertUnprocessable();
        $this->browser('POST', 'activation/consume', array_replace($payload($adapter->proof), ['school_code' => 'OTHER-SCHOOL']))->assertUnprocessable();
        $adapter->verified = false;
        $this->browser('POST', 'activation/consume', $payload($adapter->proof))->assertUnprocessable();
        $adapter->verified = true;
        $this->browser('POST', 'activation/consume', $payload($adapter->proof))->assertOk()->assertJsonPath('data.activated', true);
        $this->browser('GET', 'csrf')->assertOk();
        $this->browser('POST', 'activation/consume', $payload($adapter->proof))->assertUnprocessable();
        $this->assertDatabaseHas('schools', ['id' => $school, 'access_state' => 'suspended', 'demo_ends_at' => null, 'entitlement_ends_at' => null]);
        $this->assertDatabaseHas('memberships', ['school_id' => $school, 'role' => 'school_admin', 'status' => 'active']);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'school_admin.activation.consumed')->count());
        $this->browser('POST', $detail.'/entitlement', $grantDemo)->assertUnauthorized();
        $conversionActor = $this->authenticate();
        $this->browser('POST', $detail.'/entitlement', $grantDemo)->assertOk()
            ->assertJsonPath('data.entitlement.access', 'suspended')->assertJsonPath('data.entitlement.policy', 'demo-v1');
        $this->browser('POST', $detail.'/entitlement', $grantDemo)->assertOk()->assertJsonPath('data.replayed', true);
        $this->browser('POST', $detail.'/entitlement', ['command_id' => (string) Str::uuid(), 'action' => 'enable'])
            ->assertOk()->assertJsonPath('data.entitlement.access', 'provisional');
        $preview = $this->browser('GET', $detail.'/conversion')->assertOk()->assertJsonPath('data.eligible', true);
        $this->assertDatabaseCount('school_entitlement_events', 2);
        $finish = ['command_id' => (string) Str::uuid(), 'preview' => $preview->json('data.preview'), 'confirmed' => true, 'term_days' => 30];
        $this->browser('POST', $detail.'/conversion', array_replace($finish, ['confirmed' => false]))->assertUnprocessable();
        $this->browser('POST', $detail.'/entitlement', ['command_id' => (string) Str::uuid(), 'action' => 'suspend'])->assertOk();
        $this->browser('POST', $detail.'/conversion', $finish)->assertConflict();
        $end = DB::table('schools')->where('id', $school)->value('demo_ends_at');
        $this->travelTo(Carbon::parse($end));
        $this->assertSame('expired', app(SchoolEntitlements::class)->summary(School::findOrFail($school))['state']);
        $this->travelBack();
        $stale = $this->browser('GET', $detail.'/conversion')->assertOk()->json('data.preview');
        $record = (string) Str::ulid();
        DB::table('academic_sessions')->insert(['id' => $record, 'school_id' => $school, 'name' => 'Synthetic session',
            'starts_on' => '2027-02-01', 'ends_on' => '2027-01-01', 'created_at' => now(), 'updated_at' => now()]);
        $this->browser('GET', $detail.'/conversion')->assertOk()->assertJsonPath('data.eligible', false)
            ->assertJsonPath('data.conflicts.0.id', $record)->assertJsonPath('data.conflicts.0.code', 'invalid_session_dates');
        DB::table('academic_sessions')->where('id', $record)->update(['ends_on' => '2027-12-31']);
        $this->browser('POST', $detail.'/conversion', array_replace($finish, ['preview' => $stale]))->assertConflict();
        $service = app(SchoolEntitlements::class);
        $currentSchool = School::findOrFail($school);
        $otherActorProof = $service->conversionPreview($currentSchool, (string) Str::ulid())['preview'];
        $this->browser('POST', $detail.'/conversion', array_replace($finish, ['preview' => $otherActorProof]))->assertConflict();
        $this->travel(-10)->minutes();
        $expiredProof = $service->conversionPreview($currentSchool, $conversionActor->id)['preview'];
        $this->travelBack();
        $this->browser('POST', $detail.'/conversion', array_replace($finish, ['preview' => $expiredProof]))->assertConflict();
        $fresh = $this->browser('GET', $detail.'/conversion')->assertOk()->assertJsonPath('data.record_counts.academic_sessions', 1)->json('data.preview');
        $finish['preview'] = $fresh;
        $this->browser('POST', $detail.'/conversion', $finish)->assertOk()
            ->assertJsonPath('data.entitlement.policy', 'free-live-v1')->assertJsonPath('data.entitlement.access', 'suspended');
        $this->browser('POST', $detail.'/conversion', $finish)->assertOk()->assertJsonPath('data.replayed', true);
        $this->browser('POST', $detail.'/conversion', array_replace($finish, ['preview' => $stale]))->assertConflict();
        $this->browser('POST', $detail.'/conversion', array_replace($finish, ['command_id' => (string) Str::uuid()]))->assertConflict();
        $this->assertDatabaseHas('schools', ['id' => $school, 'data_stage' => 'live', 'access_state' => 'suspended']);
        $this->assertSame(1, DB::table('school_entitlement_events')->where('action', 'convert_demo')->count());
        $this->assertDatabaseHas('academic_sessions', ['id' => $record, 'school_id' => $school]);
        $this->browser('POST', $detail.'/entitlement', ['command_id' => (string) Str::uuid(), 'action' => 'enable'])
            ->assertOk()->assertJsonPath('data.entitlement.access', 'live_enabled');

        $this->browser('POST', 'platform/logout')->assertOk();
        $this->browser('GET', $detail)->assertUnauthorized();
    }

    public function test_session_review_requires_platform_context_and_approval_is_audited_once(): void
    {
        $this->travelTo(now()->setDate(2027, 4, 10)->setTime(12, 0));
        $this->authenticate();
        $requester = User::create(['name' => 'Synthetic School Admin', 'password' => 'Synthetic-School-Password']);
        $school = School::create(['code' => 'SESSION', 'name' => 'Synthetic School', 'settings' => ['timezone' => 'Asia/Kolkata'],
            'data_stage' => 'live', 'access_state' => 'live_enabled', 'entitlement_ends_at' => now()->addYear()]);
        Membership::create(['school_id' => $school->id, 'user_id' => $requester->id, 'role' => 'school_admin', 'status' => 'active', 'login_identifier' => 'ADMIN']);
        $session = (string) Str::ulid();
        DB::table('academic_sessions')->insert(['id' => $session, 'school_id' => $school->id, 'name' => 'Synthetic Term',
            'starts_on' => '2027-04-01', 'ends_on' => '2027-06-30', 'state' => 'active', 'version' => 1]);
        $id = (string) Str::ulid();
        DB::table('session_change_requests')->insert(['id' => $id, 'school_id' => $school->id, 'session_id' => $session, 'requester_id' => $requester->id,
            'proposed_start' => '2027-04-01', 'proposed_end' => '2027-07-31', 'session_version' => 1, 'state' => 'pending',
            'reason' => 'Synthetic revision', 'submit_key' => (string) Str::ulid(), 'submit_hash' => str_repeat('a', 64),
            'impact' => json_encode(['before' => ['starts_on' => '2027-04-01', 'ends_on' => '2027-06-30'],
                'proposed' => ['starts_on' => '2027-04-01', 'ends_on' => '2027-07-31'], 'class_offerings' => 0])]);
        $body = ['decision' => 'approve', 'reason' => 'Reviewed preview', 'version' => 1];
        $path = "platform/sessions/change-requests/{$id}/review";
        $this->withHeader('Idempotency-Key', (string) Str::ulid());
        $this->browser('POST', $path, $body)->assertOk()->assertJsonPath('data.state', 'approved');
        $this->browser('POST', $path, $body)->assertOk()->assertJsonPath('data.replayed', true);
        $this->browser('POST', $path, array_replace($body, ['decision' => 'reject']))->assertConflict();
        $this->assertDatabaseHas('academic_sessions', ['id' => $session, 'state' => 'active', 'version' => 2, 'ends_on' => '2027-07-31']);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'academic.session_change.approved')->count());
        $this->browser('POST', 'platform/logout')->assertOk();
        $this->browser('POST', $path, $body)->assertUnauthorized();
    }

    public function test_real_cookie_csrf_platform_enrollment_and_protected_identity(): void
    {
        $u = $this->authenticate();
        $id = app('session')->driver()->getId();
        $data = $this->sessionData();
        $this->assertSame(hash('sha256', $id), $data['platform_assurance']['session']);
        $response = $this->browser('GET', 'platform/identity')->assertOk()
            ->assertJsonPath('data.context', 'platform')->assertJsonPath('data.user_id', $u->id);
        $cookie = $response->getCookie('edu_browser_session');
        $this->assertSame($id, $cookie->getValue());
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertNull($cookie->getDomain());
        $this->assertSame('lax', $cookie->getSameSite());
        // The rotated CSRF token works on a subsequent mutation.
        $oldCookie = $this->cookie;
        $this->browser('POST', 'platform/logout')->assertOk();
        $this->browser('GET', 'platform/identity')->assertUnauthorized();
        $this->cookie = $oldCookie;
        $this->browser('GET', 'platform/identity')->assertUnauthorized();
    }

    public function test_persisted_assurance_is_invalidated_by_current_security_state(): void
    {
        $u = $this->authenticate();
        $this->travel(6)->minutes();
        $data = $this->sessionData();
        $cookie = $this->cookie;
        $row = (array) DB::table('sessions')->where('id', app('session')->driver()->getId())->first();
        foreach (['binding', 'version', 'expired', 'password', 'disabled', 'revoked', 'school'] as $case) {
            DB::table('sessions')->updateOrInsert(['id' => $row['id']], $row);
            $this->cookie = $cookie;
            app('session')->driver()->setId($row['id']);
            $changed = $data;
            if ($case === 'binding') {
                $changed['platform_assurance']['session'] = hash('sha256', 'another-session');
            }
            if ($case === 'version') {
                $changed['platform_assurance']['version'] = 'obsolete';
            }
            if ($case === 'expired') {
                $changed['platform_assurance']['at'] = now()->subMinutes(15)->timestamp;
            }
            if ($case === 'password') {
                $changed['platform_context']['credential'] = 'obsolete';
            }
            if ($case === 'disabled') {
                DB::table('users')->where('id', $u->id)->update(['status' => 'suspended']);
            }
            if ($case === 'revoked') {
                DB::table('session_revocations')->insert(['session_hash' => hash('sha256', app('session')->driver()->getId()), 'user_id' => $u->id, 'created_at' => now()]);
            }
            if ($case === 'school') {
                $changed['school_id'] = 'wrong-context';
            }
            $this->saveSession($changed);
            $this->assertContains($this->browser('GET', 'platform/identity')->status(), [401, 403], $case);
            $this->assertContains($this->browser('GET', 'platform/status')->status(), [401, 403], $case);
            $this->assertContains($this->browser('POST', 'platform/mfa/replacement/start', ['password' => 'Synthetic-Platform-Password', 'method' => 'totp', 'code' => 'not-a-code'])->status(), [401, 403], $case);
            if ($case === 'disabled') {
                DB::table('users')->where('id', $u->id)->update(['status' => 'active']);
            }
            if ($case === 'revoked') {
                DB::table('session_revocations')->delete();
            }
        }
        DB::table('sessions')->updateOrInsert(['id' => $row['id']], $row);
        $this->cookie = $cookie;
        $this->browser('GET', 'platform/identity')->assertOk();
        DB::table('sessions')->where('id', app('session')->driver()->getId())->delete();
        $this->browser('GET', 'platform/identity')->assertUnauthorized();
    }

    public function test_stale_cookies_and_copied_assurance_cannot_authorize_another_session(): void
    {
        $this->authenticate();
        $activeCookie = $this->cookie;
        $data = $this->sessionData();
        foreach ($this->staleCookies as $stale) {
            $this->cookie = $stale;
            $this->browser('GET', 'platform/identity')->assertUnauthorized();
        }
        $this->browser('GET', 'csrf')->assertOk();
        $this->saveSession($data);
        $this->browser('GET', 'platform/identity')->assertForbidden();
        $this->cookie = $activeCookie;
        $this->browser('GET', 'platform/identity')->assertOk();
        $this->browser('GET', 'me')->assertUnauthorized();
    }

    public function test_actual_password_and_enrollment_changes_revoke_assurance(): void
    {
        $u = $this->authenticate();
        $version = DB::table('mfa_credentials')->where('user_id', $u->id)->value('version');
        DB::table('mfa_credentials')->where('user_id', $u->id)->update(['version' => (string) Str::ulid()]);
        $this->browser('GET', 'platform/identity')->assertForbidden();
        DB::table('mfa_credentials')->where('user_id', $u->id)->update(['version' => $version]);
        $this->browser('GET', 'platform/identity')->assertOk();
        $id = app('session')->driver()->getId();
        $u->forceFill(['password' => 'Changed-Synthetic-Password'])->save();
        $this->assertDatabaseHas('session_revocations', ['session_hash' => hash('sha256', $id)]);
        $this->browser('GET', 'platform/identity')->assertUnauthorized();
    }

    public function test_http_audit_failure_never_grants_assurance(): void
    {
        config(['platform.synthetic_auth' => true, 'browser.origins' => ['https://web.edu.test']]);
        $u = User::create(['name' => 'Synthetic', 'password' => 'Synthetic-Platform-Password']);
        $u->forceFill(['is_platform_admin' => true])->save();
        $this->browser('GET', 'csrf')->assertOk();
        $this->browser('POST', 'platform/login', ['identifier' => $u->id, 'password' => 'Synthetic-Platform-Password'])->assertOk();
        $setup = $this->browser('POST', 'platform/mfa/enrollment', ['password' => 'Synthetic-Platform-Password'])->assertOk();
        $fail = true;
        DB::listen(function (QueryExecuted $q) use (&$fail) {
            if ($fail && str_starts_with(strtolower($q->sql), 'insert into') && str_contains($q->sql, 'platform_security_audits')) {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        $code = app(Totp::class)->code($setup->json('data.secret'), now()->timestamp);
        $this->browser('POST', 'platform/mfa/confirm', ['password' => 'Synthetic-Platform-Password', 'code' => $code])->assertStatus(500);
        $fail = false;
        $this->assertNull(DB::table('mfa_credentials')->where('user_id', $u->id)->value('confirmed_at'));
        $this->assertDatabaseCount('mfa_recovery_codes', 0);
        $this->assertArrayNotHasKey('platform_assurance', $this->sessionData());
        $this->browser('GET', 'platform/identity')->assertForbidden();
    }

    public function test_activity_does_not_extend_absolute_context_or_mfa_lifetime(): void
    {
        $this->authenticate();
        $data = $this->sessionData();
        $issued = $data['platform_context']['issued_at'];
        $at = $data['platform_assurance']['at'];
        $this->travel(5)->minutes();
        $this->browser('GET', 'platform/identity')->assertOk();
        $after = $this->sessionData();
        $this->assertSame($issued, $after['platform_context']['issued_at']);
        $this->assertSame($at, $after['platform_assurance']['at']);
        $this->assertSame(now()->timestamp, $after['platform_context']['seen_at']);
        $this->travel(10)->minutes();
        $this->browser('GET', 'platform/identity')->assertForbidden();
        $data['platform_assurance']['at'] = now()->timestamp;
        $data['platform_context']['issued_at'] = now()->subHours(12)->timestamp;
        $data['platform_context']['seen_at'] = now()->timestamp;
        $this->saveSession($data);
        $this->browser('GET', 'platform/identity')->assertForbidden();
    }

    public function test_verified_context_status_and_replacement_survive_initial_challenge_deadline(): void
    {
        $this->travelTo(now()->startOfMinute());
        $user = $this->authenticate();
        $start = now()->copy();
        $seed = Crypt::decryptString(DB::table('mfa_credentials')->where('user_id', $user->id)->value('secret_encrypted'));
        $this->travelTo($start->copy()->addMinutes(5));
        $this->browser('GET', 'platform/identity')->assertOk();
        $this->browser('GET', 'platform/status')->assertOk()->assertJsonPath('data.verified', true);
        $this->browser('POST', 'platform/mfa/challenge', ['password' => 'Synthetic-Platform-Password', 'code' => app(Totp::class)->code($seed, now()->timestamp)])->assertForbidden();
        $this->travelTo($start->copy()->addMinutes(6));
        $pending = $this->browser('POST', 'platform/mfa/replacement/start', ['password' => 'Synthetic-Platform-Password', 'method' => 'totp', 'code' => app(Totp::class)->code($seed, now()->timestamp)])->assertOk();
        $this->travel(1)->seconds();
        $this->browser('POST', 'platform/mfa/replacement/confirm', ['password' => 'Synthetic-Platform-Password', 'replacement_id' => $pending->json('data.replacement_id'), 'code' => app(Totp::class)->code($pending->json('data.secret'), now()->timestamp)])->assertOk()->assertJsonPath('data.reauthentication_required', true);
        $this->browser('GET', 'platform/identity')->assertUnauthorized();
    }

    public function test_replacement_started_before_challenge_expiry_can_confirm_after_it_with_valid_assurance(): void
    {
        $this->travelTo(now()->startOfMinute());
        $user = $this->authenticate();
        $seed = Crypt::decryptString(DB::table('mfa_credentials')->where('user_id', $user->id)->value('secret_encrypted'));
        $this->travel(299)->seconds();
        $pending = $this->browser('POST', 'platform/mfa/replacement/start', ['password' => 'Synthetic-Platform-Password', 'method' => 'totp', 'code' => app(Totp::class)->code($seed, now()->timestamp)])->assertOk();
        $this->travel(2)->seconds();
        $this->browser('GET', 'platform/identity')->assertOk();
        $this->browser('POST', 'platform/mfa/replacement/confirm', ['password' => 'Synthetic-Platform-Password', 'replacement_id' => $pending->json('data.replacement_id'), 'code' => app(Totp::class)->code($pending->json('data.secret'), now()->timestamp)])->assertOk();
    }

    public function test_pending_enrollment_cannot_complete_at_or_after_five_minutes(): void
    {
        $this->travelTo(now()->startOfMinute());
        config(['platform.synthetic_auth' => true, 'browser.origins' => ['https://web.edu.test']]);
        $user = User::create(['name' => 'Synthetic pending', 'password' => 'Synthetic-Platform-Password']);
        $user->forceFill(['is_platform_admin' => true])->save();
        $this->browser('GET', 'csrf')->assertOk();
        $this->browser('POST', 'platform/login', ['identifier' => $user->id, 'password' => 'Synthetic-Platform-Password'])->assertOk();
        $this->travel(299)->seconds();
        $this->browser('GET', 'platform/status')->assertOk()->assertJsonPath('data.verified', false);
        $setup = $this->browser('POST', 'platform/mfa/enrollment', ['password' => 'Synthetic-Platform-Password'])->assertOk();
        $this->travel(1)->seconds();
        $this->browser('GET', 'platform/status')->assertForbidden();
        $this->browser('GET', 'platform/identity')->assertForbidden();
        $this->browser('POST', 'platform/mfa/confirm', ['password' => 'Synthetic-Platform-Password', 'code' => app(Totp::class)->code($setup->json('data.secret'), now()->timestamp)])->assertForbidden();
        $this->assertNull(DB::table('mfa_credentials')->where('user_id', $user->id)->value('confirmed_at'));
        $this->assertArrayNotHasKey('platform_assurance', $this->sessionData());
    }

    public function test_expired_pending_challenge_cannot_use_existing_totp_recovery_or_replacement(): void
    {
        $this->travelTo(now()->startOfMinute());
        $user = $this->authenticate();
        $seed = Crypt::decryptString(DB::table('mfa_credentials')->where('user_id', $user->id)->value('secret_encrypted'));
        $this->browser('POST', 'platform/logout')->assertOk();
        $this->browser('POST', 'platform/login', ['identifier' => $user->id, 'password' => 'Synthetic-Platform-Password'])->assertOk();
        $this->travel(299)->seconds();
        $this->browser('GET', 'platform/status')->assertOk()->assertJsonPath('data.verified', false);
        $this->travel(1)->seconds();
        $this->browser('GET', 'platform/status')->assertForbidden();
        $this->browser('GET', 'platform/identity')->assertForbidden();
        $this->browser('POST', 'platform/mfa/challenge', ['password' => 'Synthetic-Platform-Password', 'code' => app(Totp::class)->code($seed, now()->timestamp)])->assertForbidden();
        $recovery = str_repeat('a', 32);
        DB::table('mfa_recovery_codes')->insert(['id' => (string) Str::ulid(), 'user_id' => $user->id, 'code_hash' => hash('sha256', $recovery), 'created_at' => now(), 'updated_at' => now()]);
        $this->browser('POST', 'platform/mfa/recovery', ['password' => 'Synthetic-Platform-Password', 'code' => $recovery])->assertForbidden();
        $this->browser('POST', 'platform/mfa/replacement/start', ['password' => 'Synthetic-Platform-Password', 'method' => 'recovery', 'code' => $recovery])->assertForbidden();
        $this->assertNull(DB::table('mfa_recovery_codes')->where('code_hash', hash('sha256', $recovery))->value('consumed_at'));
        $this->assertDatabaseCount('mfa_replacements', 0);
    }

    public function test_verified_assurance_expires_at_fifteen_minutes_even_for_inflight_replacement(): void
    {
        $this->travelTo(now()->startOfMinute());
        $user = $this->authenticate();
        $seed = Crypt::decryptString(DB::table('mfa_credentials')->where('user_id', $user->id)->value('secret_encrypted'));
        $this->travel(899)->seconds();
        $this->browser('GET', 'platform/identity')->assertOk();
        $this->browser('GET', 'platform/status')->assertOk()->assertJsonPath('data.verified', true);
        $pending = $this->browser('POST', 'platform/mfa/replacement/start', ['password' => 'Synthetic-Platform-Password', 'method' => 'totp', 'code' => app(Totp::class)->code($seed, now()->timestamp)])->assertOk();
        $credential = (array) DB::table('mfa_credentials')->where('user_id', $user->id)->first();
        $this->travel(1)->seconds();
        $this->browser('GET', 'platform/identity')->assertForbidden();
        $this->browser('GET', 'platform/status')->assertForbidden();
        $this->browser('POST', 'platform/mfa/replacement/start', ['password' => 'Synthetic-Platform-Password', 'method' => 'totp', 'code' => app(Totp::class)->code($seed, now()->timestamp)])->assertForbidden();
        $this->browser('POST', 'platform/mfa/replacement/confirm', ['password' => 'Synthetic-Platform-Password', 'replacement_id' => $pending->json('data.replacement_id'), 'code' => app(Totp::class)->code($pending->json('data.secret'), now()->timestamp)])->assertForbidden();
        $this->assertSame($credential, (array) DB::table('mfa_credentials')->where('user_id', $user->id)->first());
        $this->assertDatabaseHas('mfa_replacements', ['id' => $pending->json('data.replacement_id')]);
    }

    public function test_idle_and_absolute_context_boundaries_remain_strict_with_fresh_assurance(): void
    {
        $this->travelTo(now()->startOfMinute());
        $this->authenticate();
        $base = now()->copy();
        $original = $this->sessionData();
        foreach (['idle' => 1800, 'absolute' => 43200] as $boundary => $limit) {
            $this->travelTo($base);
            $data = $original;
            // Isolate each lifetime in persisted server state; never accept timestamps from a client.
            $data['platform_context']['issued_at'] = $base->timestamp - $limit + 1;
            $data['platform_context']['seen_at'] = $boundary === 'idle' ? $base->timestamp - $limit + 1 : $base->timestamp;
            $this->saveSession($data);
            $this->browser('GET', 'platform/status')->assertOk()->assertJsonPath('data.verified', true);
            $this->travel(1)->seconds();
            $this->browser('GET', 'platform/status')->assertForbidden();
            $this->browser('GET', 'platform/identity')->assertForbidden();
            $this->browser('POST', 'platform/mfa/replacement/start', ['password' => 'Synthetic-Platform-Password', 'method' => 'totp', 'code' => '123456'])->assertForbidden();
        }
    }

    public function test_platform_mutations_preserve_origin_csrf_and_production_denial(): void
    {
        config(['platform.synthetic_auth' => true, 'browser.origins' => ['https://web.edu.test']]);
        $this->withServerVariables(['HTTPS' => 'on', 'SERVER_PORT' => 443]);
        $this->postJson('https://localhost/api/v1/browser/platform/login', [], ['Origin' => 'https://hostile.invalid'])->assertForbidden();
        $this->postJson('https://localhost/api/v1/browser/platform/login', [], ['Origin' => 'https://web.edu.test'])->assertStatus(419);
        $this->postJson('https://localhost/api/v1/browser/platform/login', [], ['Origin' => 'https://web.edu.test', 'X-CSRF-TOKEN' => 'invalid'])->assertStatus(419);
        $this->getJson('https://localhost/api/v1/browser/platform/identity', ['Origin' => 'https://web.edu.test', 'Authorization' => 'Bearer synthetic'])->assertForbidden();
        app()->instance('env', 'production');
        $response = $this->getJson('https://localhost/api/v1/browser/platform/status', ['Origin' => 'https://web.edu.test']);
        $this->assertContains($response->status(), [401, 403]);
        $this->assertDatabaseCount('platform_security_audits', 0);
        $this->assertDatabaseCount('mfa_credentials', 0);
    }
}
