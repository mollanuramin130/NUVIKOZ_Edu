<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IsolatedE2ESeeder extends Seeder
{
    /**
     * Safety assertion ensuring the seeder never executes in production
     * or against non-disposable databases.
     *
     * @throws \RuntimeException
     */
    public static function assertEnvironmentSafe(): void
    {
        // 1. Explicit production check
        if (app()->isProduction() || app()->environment('production')) {
            throw new \RuntimeException('CRITICAL SECURITY VIOLATION: IsolatedE2ESeeder is prohibited in production.');
        }

        $isTesting = app()->environment('testing');
        $optIn = env('ALLOW_DISPOSABLE_E2E_SEEDING');

        // 2. Dedicated seeding opt-in (required in local/staging; bypassed only in test suite)
        if (! $isTesting && $optIn !== 'true') {
            throw new \RuntimeException('CRITICAL: Disposable E2E seeding is not authorized. ALLOW_DISPOSABLE_E2E_SEEDING=true is required.');
        }

        // 3. Database connection identity and name validation
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");
        $database = (string) config("database.connections.{$connection}.database");

        // Allow SQLite :memory: or explicit test databases in testing mode
        if ($isTesting && $driver === 'sqlite') {
            if ($database !== ':memory:' && ! str_contains($database, 'test')) {
                throw new \RuntimeException("CRITICAL: SQLite database '{$database}' is not an authorized test database.");
            }

            return;
        }

        // For non-sqlite connections (e.g. MariaDB / MySQL)
        if (empty($database) || in_array($database, ['production', 'nuvikoz', 'nuvikoz_prod', 'forge', 'laravel'], true)) {
            throw new \RuntimeException("CRITICAL: Unsafe database name '{$database}' detected.");
        }

        $disposableMarkers = ['_dev_', '_e2e_', '_test_', 'disposable', 'edu_dev_e2e'];
        $isDisposableName = false;
        foreach ($disposableMarkers as $marker) {
            if (str_contains(strtolower($database), $marker)) {
                $isDisposableName = true;
                break;
            }
        }

        if (! $isDisposableName) {
            throw new \RuntimeException("CRITICAL: Database '{$database}' does not contain required disposable markers (_dev_, _e2e_, _test_, disposable).");
        }
    }

    /**
     * Execute the synthetic database seeding.
     */
    public function run(): void
    {
        self::assertEnvironmentSafe();

        DB::transaction(function (): void {
            $this->seedSchoolOne();
            $this->seedSchoolTwo();
        });
    }

    /**
     * Seed School 1 (DEVTEST01) with full catalog hierarchy and 26 sections for cursor pagination.
     */
    private function seedSchoolOne(): void
    {
        // 1. School 1
        $school = School::updateOrCreate(
            ['code' => 'DEVTEST01'],
            [
                'name' => 'Staging Test Academy 01',
                'data_stage' => 'live',
                'access_state' => 'live_enabled',
                'entitlement_ends_at' => CarbonImmutable::parse('2028-12-31 23:59:59'),
                'settings' => ['timezone' => 'Asia/Kolkata'],
                'version' => 1,
            ]
        );

        // 2. School Admin (dev_admin)
        $adminPassword = env('E2E_ADMIN_PASSWORD', 'DevAdmin#2026!Sec');
        $adminUser = User::firstOrCreate(
            ['name' => 'Dev Admin'],
            [
                'password' => $adminPassword,
                'status' => 'active',
                'is_platform_admin' => false,
                'must_change_password' => false,
                'activation_required' => false,
            ]
        );

        $adminMembership = Membership::firstOrCreate(
            ['school_id' => $school->id, 'login_identifier' => 'dev_admin'],
            [
                'user_id' => $adminUser->id,
                'role' => Role::SchoolAdmin,
                'status' => 'active',
            ]
        );

        // Step-Up MFA Enrollment for Admin
        $totpSeed = env('E2E_MFA_TOTP_SEED', 'JBSWY3DPEHPK3PXP');
        DB::table('mfa_credentials')->updateOrInsert(
            ['user_id' => $adminUser->id],
            [
                'secret_encrypted' => Crypt::encryptString($totpSeed),
                'version' => (string) Str::ulid(),
                'confirmed_at' => now(),
                'last_counter' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // 3. Teacher (dev_teacher)
        $teacherPassword = env('E2E_TEACHER_PASSWORD', 'DevTeach#2026!Sec');
        $teacherUser = User::firstOrCreate(
            ['name' => 'Dev Teacher'],
            [
                'password' => $teacherPassword,
                'status' => 'active',
                'is_platform_admin' => false,
                'must_change_password' => false,
                'activation_required' => false,
            ]
        );

        Membership::firstOrCreate(
            ['school_id' => $school->id, 'login_identifier' => 'dev_teacher'],
            [
                'user_id' => $teacherUser->id,
                'role' => Role::Teacher,
                'status' => 'active',
            ]
        );

        // 4. Academic Sessions
        // Active Session (2026-2027)
        $activeSession = DB::table('academic_sessions')
            ->where('school_id', $school->id)
            ->where('name', 'Academic Year 2026-2027')
            ->first();

        if (! $activeSession) {
            $activeSessionId = (string) Str::ulid();
            DB::table('academic_sessions')->insert([
                'id' => $activeSessionId,
                'school_id' => $school->id,
                'name' => 'Academic Year 2026-2027',
                'starts_on' => '2026-06-01',
                'ends_on' => '2027-05-31',
                'state' => 'active',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $activeSessionId = $activeSession->id;
        }

        // Future Session (2027-2028)
        $futureSession = DB::table('academic_sessions')
            ->where('school_id', $school->id)
            ->where('name', 'Academic Year 2027-2028')
            ->first();

        if (! $futureSession) {
            DB::table('academic_sessions')->insert([
                'id' => (string) Str::ulid(),
                'school_id' => $school->id,
                'name' => 'Academic Year 2027-2028',
                'starts_on' => '2027-06-01',
                'ends_on' => '2028-05-31',
                'state' => 'future',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 5. Classes (Grade 9 through Grade 12)
        $classDefinitions = [
            ['name' => 'Grade 9', 'grade_label' => '9', 'sort_order' => 10],
            ['name' => 'Grade 10', 'grade_label' => '10', 'sort_order' => 20],
            ['name' => 'Grade 11', 'grade_label' => '11', 'sort_order' => 30],
            ['name' => 'Grade 12', 'grade_label' => '12', 'sort_order' => 40],
        ];

        $classIds = [];
        foreach ($classDefinitions as $def) {
            $class = DB::table('classes')
                ->where('school_id', $school->id)
                ->where('name', $def['name'])
                ->first();

            if (! $class) {
                $classId = (string) Str::ulid();
                DB::table('classes')->insert([
                    'id' => $classId,
                    'school_id' => $school->id,
                    'name' => $def['name'],
                    'grade_label' => $def['grade_label'],
                    'sort_order' => $def['sort_order'],
                    'version' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $classId = $class->id;
            }
            $classIds[$def['name']] = $classId;
        }

        // 6. Class Offerings (Offerings for Grades 9-12 in Active Session)
        $offeringIds = [];
        foreach ($classIds as $className => $cId) {
            $offering = DB::table('class_offerings')
                ->where('school_id', $school->id)
                ->where('session_id', $activeSessionId)
                ->where('class_id', $cId)
                ->first();

            if (! $offering) {
                $offeringId = (string) Str::ulid();
                DB::table('class_offerings')->insert([
                    'id' => $offeringId,
                    'school_id' => $school->id,
                    'session_id' => $activeSessionId,
                    'class_id' => $cId,
                    'version' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $offeringId = $offering->id;
            }
            $offeringIds[$className] = $offeringId;
        }

        // 7. Sections under Grade 10 (Exactly 26 Sections: Section A through Section Z)
        // Deterministic ULIDs with increasing timestamps to guarantee alphabetical and cursor pagination order.
        $grade10OfferingId = $offeringIds['Grade 10'];
        $baseTimestamp = CarbonImmutable::parse('2026-01-01 10:00:00');

        for ($i = 0; $i < 26; $i++) {
            $sectionLetter = chr(65 + $i); // 'A' through 'Z'
            $sectionName = "Section {$sectionLetter}";

            $existingSection = DB::table('sections')
                ->where('school_id', $school->id)
                ->where('class_offering_id', $grade10OfferingId)
                ->where('name', $sectionName)
                ->first();

            if (! $existingSection) {
                // Ensure strictly monotonic ULIDs for deterministic cursor pagination
                $sectionId = (string) Str::ulid($baseTimestamp->addSeconds($i * 60));
                DB::table('sections')->insert([
                    'id' => $sectionId,
                    'school_id' => $school->id,
                    'class_offering_id' => $grade10OfferingId,
                    'name' => $sectionName,
                    'class_teacher_membership_id' => null,
                    'version' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // 8. Subjects
        $subjectDefs = [
            ['code' => 'MATH', 'name' => 'Mathematics'],
            ['code' => 'PHYS', 'name' => 'Physics'],
            ['code' => 'CHEM', 'name' => 'Chemistry'],
            ['code' => 'BIOL', 'name' => 'Biology'],
            ['code' => 'ENG', 'name' => 'English Literature'],
            ['code' => 'HIST', 'name' => 'World History'],
        ];

        $subjectIds = [];
        foreach ($subjectDefs as $sDef) {
            $subject = DB::table('subjects')
                ->where('school_id', $school->id)
                ->where('code', $sDef['code'])
                ->first();

            if (! $subject) {
                $subId = (string) Str::ulid();
                DB::table('subjects')->insert([
                    'id' => $subId,
                    'school_id' => $school->id,
                    'code' => $sDef['code'],
                    'name' => $sDef['name'],
                    'version' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $subId = $subject->id;
            }
            $subjectIds[$sDef['code']] = $subId;
        }

        // 9. Subject Offerings for Grade 10 Offering
        foreach ($subjectIds as $sCode => $sId) {
            $compulsory = in_array($sCode, ['MATH', 'ENG'], true);

            $subOffering = DB::table('subject_offerings')
                ->where('school_id', $school->id)
                ->where('class_offering_id', $grade10OfferingId)
                ->where('subject_id', $sId)
                ->first();

            if (! $subOffering) {
                DB::table('subject_offerings')->insert([
                    'id' => (string) Str::ulid(),
                    'school_id' => $school->id,
                    'class_offering_id' => $grade10OfferingId,
                    'subject_id' => $sId,
                    'compulsory' => $compulsory,
                    'version' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Seed School 2 (DEVTEST02) with independent catalog records to verify tenant isolation.
     */
    private function seedSchoolTwo(): void
    {
        // 1. School 2
        $school = School::updateOrCreate(
            ['code' => 'DEVTEST02'],
            [
                'name' => 'Staging Test Academy 02',
                'data_stage' => 'live',
                'access_state' => 'live_enabled',
                'entitlement_ends_at' => CarbonImmutable::parse('2028-12-31 23:59:59'),
                'settings' => ['timezone' => 'Asia/Kolkata'],
                'version' => 1,
            ]
        );

        // 2. School 2 Admin
        $adminPassword = env('E2E_ADMIN2_PASSWORD', 'DevAdmin2#2026!Sec');
        $adminUser = User::firstOrCreate(
            ['name' => 'Dev Admin 02'],
            [
                'password' => $adminPassword,
                'status' => 'active',
                'is_platform_admin' => false,
                'must_change_password' => false,
                'activation_required' => false,
            ]
        );

        Membership::firstOrCreate(
            ['school_id' => $school->id, 'login_identifier' => 'dev_admin2'],
            [
                'user_id' => $adminUser->id,
                'role' => Role::SchoolAdmin,
                'status' => 'active',
            ]
        );

        // 3. Academic Session for School 2
        $session = DB::table('academic_sessions')
            ->where('school_id', $school->id)
            ->where('name', 'Academic Year 2026-2027 (School 2)')
            ->first();

        if (! $session) {
            $sessionId = (string) Str::ulid();
            DB::table('academic_sessions')->insert([
                'id' => $sessionId,
                'school_id' => $school->id,
                'name' => 'Academic Year 2026-2027 (School 2)',
                'starts_on' => '2026-06-01',
                'ends_on' => '2027-05-31',
                'state' => 'active',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $sessionId = $session->id;
        }

        // 4. Class for School 2
        $class = DB::table('classes')
            ->where('school_id', $school->id)
            ->where('name', 'Grade 1')
            ->first();

        if (! $class) {
            $classId = (string) Str::ulid();
            DB::table('classes')->insert([
                'id' => $classId,
                'school_id' => $school->id,
                'name' => 'Grade 1',
                'grade_label' => '1',
                'sort_order' => 10,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $classId = $class->id;
        }

        // 5. Class Offering for School 2
        $offering = DB::table('class_offerings')
            ->where('school_id', $school->id)
            ->where('session_id', $sessionId)
            ->where('class_id', $classId)
            ->first();

        if (! $offering) {
            $offeringId = (string) Str::ulid();
            DB::table('class_offerings')->insert([
                'id' => $offeringId,
                'school_id' => $school->id,
                'session_id' => $sessionId,
                'class_id' => $classId,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $offeringId = $offering->id;
        }

        // 6. Section for School 2
        $section = DB::table('sections')
            ->where('school_id', $school->id)
            ->where('class_offering_id', $offeringId)
            ->where('name', 'Section A')
            ->first();

        if (! $section) {
            DB::table('sections')->insert([
                'id' => (string) Str::ulid(),
                'school_id' => $school->id,
                'class_offering_id' => $offeringId,
                'name' => 'Section A',
                'class_teacher_membership_id' => null,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 7. Subject for School 2
        $subject = DB::table('subjects')
            ->where('school_id', $school->id)
            ->where('code', 'GENSCI')
            ->first();

        if (! $subject) {
            $subjectId = (string) Str::ulid();
            DB::table('subjects')->insert([
                'id' => $subjectId,
                'school_id' => $school->id,
                'code' => 'GENSCI',
                'name' => 'General Science',
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $subjectId = $subject->id;
        }

        // 8. Subject Offering for School 2
        $subOffering = DB::table('subject_offerings')
            ->where('school_id', $school->id)
            ->where('class_offering_id', $offeringId)
            ->where('subject_id', $subjectId)
            ->first();

        if (! $subOffering) {
            DB::table('subject_offerings')->insert([
                'id' => (string) Str::ulid(),
                'school_id' => $school->id,
                'class_offering_id' => $offeringId,
                'subject_id' => $subjectId,
                'compulsory' => true,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
