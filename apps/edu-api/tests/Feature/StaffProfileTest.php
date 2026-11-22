<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\School;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\StaffProfiles;
use App\Services\Totp;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StaffProfileTest extends TestCase
{
    use RefreshDatabase;

    private string $cookie = '';

    private string $csrf = '';

    private function school(array $overrides = []): School
    {
        return School::create($overrides + ['code' => 'S'.Str::ulid(), 'name' => 'Synthetic staff school',
            'data_stage' => 'live', 'access_state' => 'live_enabled', 'entitlement_ends_at' => now()->addDay()]);
    }

    private function member(School $school, string $role = 'teacher', array $attributes = []): Membership
    {
        $user = User::create($attributes + ['name' => 'Synthetic % staff', 'password' => 'Synthetic-Staff-Password']);

        return Membership::create(['school_id' => $school->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active', 'login_identifier' => (string) Str::ulid()]);
    }

    private function browser(string $method, string $path, array $body = [], ?string $key = null): TestResponse
    {
        app('auth')->forgetGuards();
        app('auth')->shouldUse('web');
        app()->forgetInstance('auth.driver');
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        $this->withCredentials()->withServerVariables(['HTTPS' => 'on', 'SERVER_PORT' => 443])
            ->withUnencryptedCookie('edu_browser_session', $this->cookie);
        $headers = ['Origin' => 'https://web.edu.test', 'X-CSRF-TOKEN' => $this->csrf];
        if ($key !== null) {
            $headers['Idempotency-Key'] = $key;
        }
        $response = $this->json($method, 'https://localhost/api/v1/browser/'.$path, $body, $headers);
        if ($cookie = $response->getCookie('edu_browser_session', false)) {
            $this->cookie = $cookie->getValue();
        }
        if ($token = $response->json('data.csrf_token')) {
            $this->csrf = $token;
        }

        return $response;
    }

    private function login(Membership $admin): void
    {
        config(['browser.origins' => ['https://web.edu.test']]);
        $this->browser('GET', 'csrf')->assertOk();
        $this->browser('POST', 'login', ['school_code' => $admin->school->code, 'identifier' => $admin->login_identifier,
            'password' => 'Synthetic-Staff-Password'])->assertOk();
    }

    private function confirmedMfa(): void
    {
        $seed = $this->browser('POST', 'security/mfa/enrollment', ['password' => 'Synthetic-Staff-Password'])->assertOk()->json('data.secret');
        $this->browser('POST', 'security/mfa/enrollment/confirm', ['password' => 'Synthetic-Staff-Password',
            'code' => app(Totp::class)->code($seed, now()->timestamp)])->assertOk();
    }

    public function test_browser_registration_projection_replay_and_tenant_boundaries(): void
    {
        $school = $this->school();
        $admin = $this->member($school, 'school_admin');
        $teacher = $this->member($school);
        $principal = $this->member($school, 'principal');
        $foreign = $this->member($this->school());
        $pending = $this->member($school, attributes: ['activation_required' => true]);
        $family = $this->member($school, 'parent');
        $key = (string) Str::ulid();
        $this->login($admin);
        $this->browser('GET', 'staff')->assertForbidden()->assertJsonPath('error.code', 'MFA_REQUIRED');
        $this->confirmedMfa();
        $this->browser('GET', 'staff/eligible-memberships?q='.rawurlencode('Synthetic %'))->assertOk()->assertJsonCount(2, 'data.items');
        $this->browser('GET', 'staff/eligible-memberships?q='.rawurlencode('Synthetic _'))->assertOk()->assertJsonCount(0, 'data.items');
        $this->browser('POST', 'staff', ['membership_id' => $foreign->id], $key)->assertNotFound();
        $this->browser('POST', 'staff', ['membership_id' => $pending->id], $key)->assertConflict()->assertJsonPath('error.code', 'STAFF_MEMBERSHIP_INELIGIBLE');
        $this->browser('POST', 'staff', ['membership_id' => $family->id], $key)->assertConflict();
        $this->browser('POST', 'staff', ['membership_id' => $teacher->id, 'school_id' => $school->id], $key)->assertUnprocessable();

        $result = $this->browser('POST', 'staff', ['membership_id' => $teacher->id], $key)->assertCreated()->json('data');
        $id = $result['resource']['id'];
        $this->assertFalse($result['replayed']);
        $this->assertSame(['id', 'membership_id', 'display_name', 'role', 'eligible_for_registration', 'created_at', 'updated_at'], array_keys($result['resource']));
        $this->browser('GET', 'staff/'.$id)->assertOk()->assertJsonPath('data.membership_id', $teacher->id);
        $this->browser('POST', 'staff', ['membership_id' => strtoupper($teacher->id)], $key)->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.resource.id', $id);
        $this->browser('POST', 'staff', ['membership_id' => $principal->id], $key)->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
        $this->browser('POST', 'staff', ['membership_id' => $teacher->id], (string) Str::ulid())->assertConflict()->assertJsonPath('error.code', 'STAFF_PROFILE_EXISTS');
        $this->browser('POST', 'staff', ['membership_id' => $principal->id], (string) Str::ulid())->assertCreated();
        $this->browser('GET', 'staff')->assertOk()->assertJsonCount(2, 'data.items');
        $this->browser('GET', 'staff/eligible-memberships')->assertOk()->assertJsonCount(0, 'data.items');
        $this->assertDatabaseCount('staff_profiles', 2);
        $this->assertDatabaseCount('users', 6);
        $this->assertDatabaseCount('memberships', 6);
        $audit = AuditEvent::where('action', 'staff.profile.created')->where('resource_id', $id)->sole();
        $this->assertSame(['membership_id' => $teacher->id, 'role' => 'teacher'], $audit->details);
        $this->assertSame(2, DB::table('idempotency_records')->where('operation', 'staff.profile.register')->count());
        $teacher->update(['status' => 'inactive']);
        $this->browser('GET', 'staff/'.$id)->assertOk()->assertJsonPath('data.eligible_for_registration', false);
        $this->browser('POST', 'staff', ['membership_id' => $teacher->id], $key)->assertConflict();
        $school->update(['entitlement_ends_at' => now()->subSecond()]);
        $this->browser('GET', 'staff')->assertOk()->assertJsonCount(2, 'data.items');
        $this->browser('POST', 'staff', ['membership_id' => $teacher->id], $key)->assertForbidden();
        $school->update(['access_state' => 'suspended']);
        $this->browser('GET', 'staff')->assertUnauthorized();
    }

    public function test_target_with_mandatory_password_change_cannot_register(): void
    {
        $school = $this->school();
        $admin = $this->member($school, 'school_admin');
        $target = $this->member($school, attributes: ['activation_required' => false, 'must_change_password' => true]);
        $control = $this->member($school, 'principal');
        $this->login($admin);
        $this->confirmedMfa();

        $items = $this->browser('GET', 'staff/eligible-memberships')->assertOk()->json('data.items');
        $this->assertSame([$control->id], array_column($items, 'membership_id'));
        $this->browser('POST', 'staff', ['membership_id' => $target->id], (string) Str::ulid())
            ->assertConflict()->assertJsonPath('error.code', 'STAFF_MEMBERSHIP_INELIGIBLE');
        $this->assertDatabaseCount('staff_profiles', 0);
        $this->assertDatabaseMissing('idempotency_records', ['operation' => 'staff.profile.register']);
        $this->assertDatabaseMissing('audit_events', ['action' => 'staff.profile.created']);
    }

    public function test_archived_school_denies_each_protected_staff_read_and_retains_history(): void
    {
        $school = $this->school();
        $admin = $this->member($school, 'school_admin');
        $target = $this->member($school, attributes: ['name' => 'Synthetic archived profile']);
        $candidate = $this->member($school, 'principal', ['name' => 'Synthetic archived candidate']);
        $this->login($admin);
        $this->confirmedMfa();
        $profile = $this->browser('POST', 'staff', ['membership_id' => $target->id], (string) Str::ulid())
            ->assertCreated()->json('data.resource.id');
        $this->browser('GET', 'staff')->assertOk()->assertJsonPath('data.items.0.id', $profile);
        $this->browser('GET', 'staff/'.$profile)->assertOk()->assertJsonPath('data.display_name', $target->user->name);
        $this->browser('GET', 'staff/eligible-memberships')->assertOk()->assertJsonPath('data.items.0.membership_id', $candidate->id);
        $sessions = ['staff' => $this->cookie];
        foreach (['staff/'.$profile, 'staff/eligible-memberships'] as $path) {
            $this->cookie = '';
            $this->csrf = '';
            $this->login($this->member($school, 'school_admin'));
            $this->confirmedMfa();
            $this->browser('GET', $path)->assertOk();
            $sessions[$path] = $this->cookie;
        }

        $school->update(['access_state' => 'archived']);
        foreach ($sessions as $path => $cookie) {
            $this->cookie = $cookie;
            $this->browser('GET', $path)->assertUnauthorized()->assertJsonPath('error.code', 'SESSION_EXPIRED')
                ->assertJsonMissingPath('data')->assertDontSee($target->user->name)->assertDontSee($candidate->user->name);
        }
        $this->assertDatabaseHas('staff_profiles', ['id' => $profile, 'school_id' => $school->id, 'membership_id' => $target->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'staff.profile.created', 'resource_id' => $profile, 'school_id' => $school->id]);
    }

    public function test_lookup_cursor_is_exclusive_and_fixed_size(): void
    {
        $school = $this->school();
        $admin = $this->member($school, 'school_admin');
        $members = [];
        for ($i = 0; $i < 26; $i++) {
            $members[] = $this->member($school)->id;
        }
        sort($members);
        $this->member($this->school());
        $this->login($admin);
        $this->confirmedMfa();

        $first = $this->browser('GET', 'staff/eligible-memberships?q=Synthetic')->assertOk()->json('data');
        $this->assertSame(array_slice($members, 0, 25), array_column($first['items'], 'membership_id'));
        $this->assertSame($members[24], $first['next_cursor']);
        $next = $this->browser('GET', 'staff/eligible-memberships?q=Synthetic&cursor='.$first['next_cursor'])
            ->assertOk()->json('data');
        $this->assertSame([$members[25]], array_column($next['items'], 'membership_id'));
        $this->assertNull($next['next_cursor']);
        $this->browser('GET', 'staff/eligible-memberships?per_page=200')->assertUnprocessable();
    }

    public function test_demo_reads_wrong_role_and_foreign_detail_are_denied_as_specified(): void
    {
        $school = $this->school(['data_stage' => 'demo', 'demo_ends_at' => now()->addDay()]);
        $actor = $this->member($school, 'school_admin');
        $target = $this->member($school);
        $this->mfaFixture($actor);
        $service = app(StaffProfiles::class);
        $this->assertCount(1, $service->listing($actor, [], true)['items']);
        try {
            $service->register($actor, $target->id, (string) Str::ulid(), 'staff-test');
            $this->fail('Demo registration succeeded.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertDatabaseCount('staff_profiles', 0);
        try {
            $service->listing($target, [], false);
            $this->fail('Teacher received list.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $foreign = $this->member($this->school());
        $profile = StaffProfile::create(['school_id' => $foreign->school_id, 'membership_id' => $foreign->id]);
        $this->expectException(RecordNotFoundException::class);
        $service->detail($actor, $profile->id);
    }

    public function test_audit_error_rolls_back_and_populated_migration_refuses_rollback(): void
    {
        $school = $this->school();
        $actor = $this->member($school, 'school_admin');
        $target = $this->member($school);
        $this->mfaFixture($actor);
        $service = app(StaffProfiles::class);
        $fail = true;
        AuditEvent::creating(function ($audit) use (&$fail): void {
            if ($fail && $audit->action === 'staff.profile.created') {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        $key = (string) Str::ulid();
        try {
            $service->register($actor, $target->id, $key, 'staff-test');
            $this->fail('Audit failure was ignored.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic audit failure', $e->getMessage());
        }
        $this->assertDatabaseCount('staff_profiles', 0);
        $this->assertDatabaseMissing('idempotency_records', ['key' => $key]);
        $fail = false;
        $service->register($actor, $target->id, $key, 'staff-test');
        $migration = require database_path('migrations/2026_11_22_000019_create_staff_profiles.php');
        $this->expectExceptionMessage('Preserve staff profile, receipt and audit history');
        $migration->down();
    }

    public function test_composite_fk_rejects_cross_school_insert(): void
    {
        $member = $this->member($this->school());
        $this->expectException(QueryException::class);
        StaffProfile::create(['school_id' => $this->school()->id, 'membership_id' => $member->id]);
    }

    public function test_browser_boundary_rejects_hostile_origin_bearer_and_missing_csrf(): void
    {
        config(['browser.origins' => ['https://web.edu.test']]);
        $this->withServerVariables(['HTTPS' => 'on', 'SERVER_PORT' => 443]);
        $url = 'https://localhost/api/v1/browser/staff';
        $this->getJson($url, ['Origin' => 'https://hostile.invalid'])->assertForbidden()->assertJsonPath('error.code', 'ORIGIN_DENIED');
        $this->getJson($url, ['Origin' => 'https://web.edu.test', 'Authorization' => 'Bearer synthetic'])->assertForbidden()->assertJsonPath('error.code', 'BROWSER_SESSION_REQUIRED');
        $this->postJson($url, [], ['Origin' => 'https://web.edu.test'])->assertStatus(419);
        $this->getJson($url, ['Origin' => 'https://web.edu.test'])->assertUnauthorized();
    }
}
