<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Services\PrivilegedMfa;
use App\Services\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileAcademicApiTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $schoolCode = 'SCH1', string $role = 'school_admin'): array
    {
        $school = School::firstOrCreate(
            ['code' => $schoolCode],
            [
                'name' => 'Test Mobile School',
                'data_stage' => 'live',
                'access_state' => 'live_enabled',
                'entitlement_ends_at' => now()->addDay(),
                'settings' => ['timezone' => 'Asia/Kolkata'],
            ]
        );

        $user = User::create([
            'name' => 'Mobile Test Admin',
            'password' => 'SecurePass123!',
        ]);

        $membership = Membership::create([
            'school_id' => $school->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
            'login_identifier' => 'ADMIN_'.$user->id,
        ]);

        $tokenResult = $user->createToken('test_mobile_device', ['school:'.$school->id]);

        return [
            'school' => $school,
            'user' => $user,
            'membership' => $membership,
            'token' => $tokenResult->plainTextToken,
            'accessToken' => $tokenResult->accessToken,
        ];
    }

    public function test_mobile_school_admin_can_list_academic_sessions_without_step_up_mfa(): void
    {
        $setup = $this->member();

        // Seed an academic session directly in DB
        DB::table('academic_sessions')->insert([
            'id' => (string) Str::ulid(),
            'school_id' => $setup['school']->id,
            'name' => '2026-2027 Session',
            'starts_on' => '2026-06-01',
            'ends_on' => '2027-05-31',
            'state' => 'future',
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $res = $this->withHeaders([
            'Authorization' => 'Bearer '.$setup['token'],
            'X-School-ID' => $setup['school']->id,
            'Accept' => 'application/json',
        ])->getJson('/api/v1/academic/sessions');

        $res->assertStatus(200);
        $res->assertJsonPath('data.items.0.name', '2026-2027 Session');
        $res->assertJsonPath('data.next_cursor', null);
    }

    public function test_mobile_token_without_school_header_is_rejected(): void
    {
        $setup = $this->member();

        $res = $this->withHeaders([
            'Authorization' => 'Bearer '.$setup['token'],
            'Accept' => 'application/json',
        ])->getJson('/api/v1/academic/sessions');

        $res->assertStatus(403);
        $res->assertJsonPath('error.code', 'TENANT_CONTEXT_REQUIRED');
    }

    public function test_mobile_token_for_different_school_is_rejected(): void
    {
        $setup = $this->member('SCH1');

        $otherSchool = School::create([
            'code' => 'SCH2',
            'name' => 'Other School',
            'data_stage' => 'live',
            'access_state' => 'live_enabled',
            'entitlement_ends_at' => now()->addDay(),
            'settings' => ['timezone' => 'Asia/Kolkata'],
        ]);

        $res = $this->withHeaders([
            'Authorization' => 'Bearer '.$setup['token'],
            'X-School-ID' => $otherSchool->id,
            'Accept' => 'application/json',
        ])->getJson('/api/v1/academic/sessions');

        $res->assertStatus(403);
        $res->assertJsonPath('error.code', 'TOKEN_SCHOOL_MISMATCH');
    }

    public function test_non_admin_token_cannot_access_academic_catalog(): void
    {
        $setup = $this->member('SCH1', 'teacher');

        $res = $this->withHeaders([
            'Authorization' => 'Bearer '.$setup['token'],
            'X-School-ID' => $setup['school']->id,
            'Accept' => 'application/json',
        ])->getJson('/api/v1/academic/sessions');

        $res->assertStatus(403);
    }

    public function test_mobile_school_admin_cannot_create_session_without_device_mfa_assurance(): void
    {
        $setup = $this->member();

        $res = $this->withHeaders([
            'Authorization' => 'Bearer '.$setup['token'],
            'X-School-ID' => $setup['school']->id,
            'Idempotency-Key' => (string) Str::ulid(),
            'Accept' => 'application/json',
        ])->postJson('/api/v1/academic/sessions', [
            'name' => 'Future Session',
            'starts_on' => now()->addYear()->toDateString(),
            'ends_on' => now()->addYears(2)->toDateString(),
        ]);

        $res->assertStatus(403);
    }

    public function test_mobile_device_mfa_challenge_and_subsequent_creation(): void
    {
        $setup = $this->member();

        // Enroll MFA for user
        $seed = app(Totp::class)->seed();
        $version = (string) Str::ulid();
        DB::table('mfa_credentials')->insert([
            'user_id' => $setup['user']->id,
            'secret_encrypted' => Crypt::encryptString($seed),
            'version' => $version,
            'confirmed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Check MFA status before verification
        $statusRes = $this->withHeaders([
            'Authorization' => 'Bearer '.$setup['token'],
            'X-School-ID' => $setup['school']->id,
            'Accept' => 'application/json',
        ])->getJson('/api/v1/security/mfa/status');

        $statusRes->assertStatus(200);
        $statusRes->assertJsonPath('data.enrolled', true);
        $statusRes->assertJsonPath('data.verified', false);

        // Challenge with valid TOTP code
        $code = app(Totp::class)->code($seed, now()->timestamp);
        $challengeRes = $this->withHeaders([
            'Authorization' => 'Bearer '.$setup['token'],
            'X-School-ID' => $setup['school']->id,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/security/mfa/challenge', [
            'password' => 'SecurePass123!',
            'code' => $code,
            'mode' => 'totp',
        ]);

        $challengeRes->assertStatus(200);
        $challengeRes->assertJsonPath('data.verified', true);
        $challengeRes->assertJsonPath('data.assurance_seconds', 900);

        // Check MFA status after verification
        $statusAfter = $this->withHeaders([
            'Authorization' => 'Bearer '.$setup['token'],
            'X-School-ID' => $setup['school']->id,
            'Accept' => 'application/json',
        ])->getJson('/api/v1/security/mfa/status');

        $statusAfter->assertStatus(200);
        $statusAfter->assertJsonPath('data.verified', true);

        // Now create academic session with MFA assurance active
        $idempotencyKey = (string) Str::ulid();
        $createRes = $this->withHeaders([
            'Authorization' => 'Bearer '.$setup['token'],
            'X-School-ID' => $setup['school']->id,
            'Idempotency-Key' => $idempotencyKey,
            'Accept' => 'application/json',
        ])->postJson('/api/v1/academic/sessions', [
            'name' => 'Future Session '.Str::random(4),
            'starts_on' => now()->addYear()->toDateString(),
            'ends_on' => now()->addYears(2)->toDateString(),
        ]);

        $createRes->assertStatus(200);
        $createRes->assertJsonPath('data.resource.state', 'future');

        // Verify audit event
        $audit = AuditEvent::where('action', 'academic.sessions.created')->first();
        $this->assertNotNull($audit);
        $this->assertSame($setup['school']->id, $audit->school_id);
        $this->assertSame($setup['user']->id, $audit->actor_user_id);
    }

    public function test_expired_device_mfa_assurance_is_rejected(): void
    {
        $setup = $this->member();

        $seed = app(Totp::class)->seed();
        $version = (string) Str::ulid();
        DB::table('mfa_credentials')->insert([
            'user_id' => $setup['user']->id,
            'secret_encrypted' => Crypt::encryptString($seed),
            'version' => $version,
            'confirmed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Place expired proof in cache (issued 901 seconds ago)
        $binding = app(PrivilegedMfa::class)->binding($setup['membership']);
        cache()->put('device_mfa_assurance:'.$setup['accessToken']->id, [
            'version' => $version,
            'token_id' => $setup['accessToken']->id,
            'binding' => $binding,
            'at' => now()->timestamp - 901,
        ], now()->addSeconds(900));

        $res = $this->withHeaders([
            'Authorization' => 'Bearer '.$setup['token'],
            'X-School-ID' => $setup['school']->id,
            'Idempotency-Key' => (string) Str::ulid(),
            'Accept' => 'application/json',
        ])->postJson('/api/v1/academic/sessions', [
            'name' => 'Future Session Expired',
            'starts_on' => now()->addYear()->toDateString(),
            'ends_on' => now()->addYears(2)->toDateString(),
        ]);

        $res->assertStatus(403);
    }

    public function test_mobile_full_academic_hierarchy_workflow(): void
    {
        $setup = $this->member();

        // MFA Setup and Challenge
        $seed = app(Totp::class)->seed();
        DB::table('mfa_credentials')->insert([
            'user_id' => $setup['user']->id,
            'secret_encrypted' => Crypt::encryptString($seed),
            'version' => (string) Str::ulid(),
            'confirmed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $headers = [
            'Authorization' => 'Bearer '.$setup['token'],
            'X-School-ID' => $setup['school']->id,
            'Accept' => 'application/json',
        ];

        // Complete MFA challenge
        $code = app(Totp::class)->code($seed, now()->timestamp);
        $this->withHeaders($headers)->postJson('/api/v1/security/mfa/challenge', [
            'password' => 'SecurePass123!',
            'code' => $code,
            'mode' => 'totp',
        ])->assertStatus(200);

        // 1. Create Session
        $sessionRes = $this->withHeaders($headers + ['Idempotency-Key' => (string) Str::ulid()])
            ->postJson('/api/v1/academic/sessions', [
                'name' => 'Academic Year 2027-2028',
                'starts_on' => now()->addYear()->toDateString(),
                'ends_on' => now()->addYears(2)->toDateString(),
            ]);
        $sessionRes->assertStatus(200);
        $sessionId = $sessionRes->json('data.resource.id');

        // 2. Create Class
        $classRes = $this->withHeaders($headers + ['Idempotency-Key' => (string) Str::ulid()])
            ->postJson('/api/v1/academic/classes', [
                'name' => 'Standard 10',
                'grade_label' => 'X',
                'sort_order' => 10,
            ]);
        $classRes->assertStatus(200);
        $classId = $classRes->json('data.resource.id');

        // 3. Create Class Offering
        $offeringRes = $this->withHeaders($headers + ['Idempotency-Key' => (string) Str::ulid()])
            ->postJson('/api/v1/academic/class-offerings', [
                'session_id' => $sessionId,
                'class_id' => $classId,
            ]);
        $offeringRes->assertStatus(200);
        $offeringId = $offeringRes->json('data.resource.id');

        // 4. Create Section
        $sectionRes = $this->withHeaders($headers + ['Idempotency-Key' => (string) Str::ulid()])
            ->postJson('/api/v1/academic/sections', [
                'class_offering_id' => $offeringId,
                'name' => 'Section Blue',
            ]);
        $sectionRes->assertStatus(200);

        // 5. Create Subject
        $subjectRes = $this->withHeaders($headers + ['Idempotency-Key' => (string) Str::ulid()])
            ->postJson('/api/v1/academic/subjects', [
                'code' => 'PHY101',
                'name' => 'Physics Core',
            ]);
        $subjectRes->assertStatus(200);
        $subjectId = $subjectRes->json('data.resource.id');

        // 6. Create Subject Offering
        $subjectOfferingRes = $this->withHeaders($headers + ['Idempotency-Key' => (string) Str::ulid()])
            ->postJson('/api/v1/academic/subject-offerings', [
                'class_offering_id' => $offeringId,
                'subject_id' => $subjectId,
                'compulsory' => true,
            ]);
        $subjectOfferingRes->assertStatus(200);

        // Verify Listings for all 6 resources via Mobile API
        $this->withHeaders($headers)->getJson('/api/v1/academic/sessions')
            ->assertStatus(200)
            ->assertJsonPath('data.items.0.id', $sessionId);

        $this->withHeaders($headers)->getJson('/api/v1/academic/classes')
            ->assertStatus(200)
            ->assertJsonPath('data.items.0.id', $classId);

        $this->withHeaders($headers)->getJson('/api/v1/academic/class-offerings?session_id='.$sessionId)
            ->assertStatus(200)
            ->assertJsonPath('data.items.0.id', $offeringId);

        $this->withHeaders($headers)->getJson('/api/v1/academic/sections?class_offering_id='.$offeringId)
            ->assertStatus(200)
            ->assertJsonPath('data.items.0.name', 'Section Blue');

        $this->withHeaders($headers)->getJson('/api/v1/academic/subjects')
            ->assertStatus(200)
            ->assertJsonPath('data.items.0.id', $subjectId);

        $this->withHeaders($headers)->getJson('/api/v1/academic/subject-offerings?class_offering_id='.$offeringId)
            ->assertStatus(200)
            ->assertJsonPath('data.items.0.subject_id', $subjectId)
            ->assertJsonPath('data.items.0.compulsory', true);
    }
}
