<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Services\AcademicCatalog;
use Database\Seeders\IsolatedE2ESeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class IsolatedE2ESeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_refuses_production_environment(): void
    {
        $originalEnv = app()['env'];
        try {
            app()['env'] = 'production';
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('CRITICAL SECURITY VIOLATION: IsolatedE2ESeeder is prohibited in production.');
            IsolatedE2ESeeder::assertEnvironmentSafe();
        } finally {
            app()['env'] = $originalEnv;
        }
    }

    public function test_seeder_refuses_missing_opt_in_when_not_in_testing(): void
    {
        $originalEnv = app()['env'];
        $originalOptIn = env('ALLOW_DISPOSABLE_E2E_SEEDING');
        try {
            app()['env'] = 'staging';
            putenv('ALLOW_DISPOSABLE_E2E_SEEDING=false');
            $_ENV['ALLOW_DISPOSABLE_E2E_SEEDING'] = 'false';

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('ALLOW_DISPOSABLE_E2E_SEEDING=true is required');
            IsolatedE2ESeeder::assertEnvironmentSafe();
        } finally {
            app()['env'] = $originalEnv;
            if ($originalOptIn !== false && $originalOptIn !== null) {
                putenv("ALLOW_DISPOSABLE_E2E_SEEDING={$originalOptIn}");
                $_ENV['ALLOW_DISPOSABLE_E2E_SEEDING'] = $originalOptIn;
            } else {
                putenv('ALLOW_DISPOSABLE_E2E_SEEDING');
                unset($_ENV['ALLOW_DISPOSABLE_E2E_SEEDING']);
            }
        }
    }

    public function test_seeder_refuses_unsafe_database_names(): void
    {
        $originalEnv = app()['env'];
        $originalConn = config('database.default');
        $originalDb = config("database.connections.{$originalConn}.database");
        $originalDriver = config("database.connections.{$originalConn}.driver");

        try {
            app()['env'] = 'staging';
            putenv('ALLOW_DISPOSABLE_E2E_SEEDING=true');
            $_ENV['ALLOW_DISPOSABLE_E2E_SEEDING'] = 'true';

            config(["database.connections.{$originalConn}.driver" => 'mysql']);
            config(["database.connections.{$originalConn}.database" => 'nuvikoz_prod']);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage("Unsafe database name 'nuvikoz_prod' detected");
            IsolatedE2ESeeder::assertEnvironmentSafe();
        } finally {
            app()['env'] = $originalEnv;
            config(["database.connections.{$originalConn}.driver" => $originalDriver]);
            config(["database.connections.{$originalConn}.database" => $originalDb]);
            putenv('ALLOW_DISPOSABLE_E2E_SEEDING');
            unset($_ENV['ALLOW_DISPOSABLE_E2E_SEEDING']);
        }
    }

    public function test_seeder_refuses_non_disposable_database_names(): void
    {
        $originalEnv = app()['env'];
        $originalConn = config('database.default');
        $originalDb = config("database.connections.{$originalConn}.database");
        $originalDriver = config("database.connections.{$originalConn}.driver");

        try {
            app()['env'] = 'staging';
            putenv('ALLOW_DISPOSABLE_E2E_SEEDING=true');
            $_ENV['ALLOW_DISPOSABLE_E2E_SEEDING'] = 'true';

            config(["database.connections.{$originalConn}.driver" => 'mysql']);
            config(["database.connections.{$originalConn}.database" => 'shared_enterprise_schools']);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('does not contain required disposable markers');
            IsolatedE2ESeeder::assertEnvironmentSafe();
        } finally {
            app()['env'] = $originalEnv;
            config(["database.connections.{$originalConn}.driver" => $originalDriver]);
            config(["database.connections.{$originalConn}.database" => $originalDb]);
            putenv('ALLOW_DISPOSABLE_E2E_SEEDING');
            unset($_ENV['ALLOW_DISPOSABLE_E2E_SEEDING']);
        }
    }

    public function test_seeder_refuses_unauthorized_sqlite_database_path(): void
    {
        $originalConn = config('database.default');
        $originalDb = config("database.connections.{$originalConn}.database");

        try {
            config(["database.connections.{$originalConn}.database" => '/var/data/shared_prod.sqlite']);

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('is not an authorized test database');
            IsolatedE2ESeeder::assertEnvironmentSafe();
        } finally {
            config(["database.connections.{$originalConn}.database" => $originalDb]);
        }
    }

    public function test_seeder_executes_successfully_and_populates_expected_cardinality(): void
    {
        $seeder = new IsolatedE2ESeeder;
        $seeder->run();

        // 1. Two Schools
        $this->assertSame(2, School::count());
        $school1 = School::where('code', 'DEVTEST01')->firstOrFail();
        $school2 = School::where('code', 'DEVTEST02')->firstOrFail();

        $this->assertSame('Staging Test Academy 01', $school1->name);
        $this->assertSame('live_enabled', $school1->access_state);
        $this->assertSame('Asia/Kolkata', $school1->settings['timezone']);

        // 2. Identities & Memberships
        $admin1 = Membership::where('school_id', $school1->id)->where('login_identifier', 'dev_admin')->firstOrFail();
        $this->assertSame(Role::SchoolAdmin, $admin1->role);
        $this->assertSame('active', $admin1->status);

        $teacher1 = Membership::where('school_id', $school1->id)->where('login_identifier', 'dev_teacher')->firstOrFail();
        $this->assertSame(Role::Teacher, $teacher1->role);
        $this->assertSame('active', $teacher1->status);

        $admin2 = Membership::where('school_id', $school2->id)->where('login_identifier', 'dev_admin2')->firstOrFail();
        $this->assertSame(Role::SchoolAdmin, $admin2->role);

        // 3. School 1 Academic Catalog Cardinality
        // 2 Sessions (1 active, 1 future)
        $this->assertSame(2, DB::table('academic_sessions')->where('school_id', $school1->id)->count());
        $this->assertSame(1, DB::table('academic_sessions')->where('school_id', $school1->id)->where('state', 'active')->count());
        $this->assertSame(1, DB::table('academic_sessions')->where('school_id', $school1->id)->where('state', 'future')->count());

        // 4 Classes (Grades 9-12)
        $this->assertSame(4, DB::table('classes')->where('school_id', $school1->id)->count());

        // 4 Class Offerings
        $this->assertSame(4, DB::table('class_offerings')->where('school_id', $school1->id)->count());

        // Exactly 26 Sections (all under Grade 10 offering)
        $this->assertSame(26, DB::table('sections')->where('school_id', $school1->id)->count());

        // 6 Subjects
        $this->assertSame(6, DB::table('subjects')->where('school_id', $school1->id)->count());

        // 6 Subject Offerings (Math & English compulsory)
        $this->assertSame(6, DB::table('subject_offerings')->where('school_id', $school1->id)->count());
        $this->assertSame(2, DB::table('subject_offerings')->where('school_id', $school1->id)->where('compulsory', true)->count());

        // 4. School 2 Academic Catalog
        $this->assertSame(1, DB::table('academic_sessions')->where('school_id', $school2->id)->count());
        $this->assertSame(1, DB::table('classes')->where('school_id', $school2->id)->count());
        $this->assertSame(1, DB::table('class_offerings')->where('school_id', $school2->id)->count());
        $this->assertSame(1, DB::table('sections')->where('school_id', $school2->id)->count());
        $this->assertSame(1, DB::table('subjects')->where('school_id', $school2->id)->count());
        $this->assertSame(1, DB::table('subject_offerings')->where('school_id', $school2->id)->count());
    }

    public function test_seeder_is_idempotent_on_repeated_runs(): void
    {
        $seeder = new IsolatedE2ESeeder;

        // First run
        $seeder->run();

        $schoolsCount = School::count();
        $membershipsCount = Membership::count();
        $sessionsCount = DB::table('academic_sessions')->count();
        $classesCount = DB::table('classes')->count();
        $offeringsCount = DB::table('class_offerings')->count();
        $sectionsCount = DB::table('sections')->count();
        $subjectsCount = DB::table('subjects')->count();
        $subOfferingsCount = DB::table('subject_offerings')->count();

        // Second run
        $seeder->run();

        // Counts must be identical with zero duplicate rows
        $this->assertSame($schoolsCount, School::count());
        $this->assertSame($membershipsCount, Membership::count());
        $this->assertSame($sessionsCount, DB::table('academic_sessions')->count());
        $this->assertSame($classesCount, DB::table('classes')->count());
        $this->assertSame($offeringsCount, DB::table('class_offerings')->count());
        $this->assertSame($sectionsCount, DB::table('sections')->count());
        $this->assertSame($subjectsCount, DB::table('subjects')->count());
        $this->assertSame($subOfferingsCount, DB::table('subject_offerings')->count());
    }

    public function test_sections_cursor_pagination_traversal(): void
    {
        $seeder = new IsolatedE2ESeeder;
        $seeder->run();

        $school1 = School::where('code', 'DEVTEST01')->firstOrFail();
        $admin1 = Membership::where('school_id', $school1->id)->where('login_identifier', 'dev_admin')->firstOrFail();

        $service = app(AcademicCatalog::class);

        // Page 1: Query first 25 sections (device = true)
        $page1 = $service->listing($admin1, 'sections', [], true);

        $this->assertCount(25, $page1['items']);
        $this->assertNotNull($page1['next_cursor']);
        $this->assertSame('Section A', $page1['items'][0]['name']);
        $this->assertSame('Section Y', $page1['items'][24]['name']);

        // Page 2: Query remaining section using cursor
        $page2 = $service->listing($admin1, 'sections', ['cursor' => $page1['next_cursor']], true);

        $this->assertCount(1, $page2['items']);
        $this->assertSame('Section Z', $page2['items'][0]['name']);
        $this->assertNull($page2['next_cursor']);
    }

    public function test_two_school_tenant_isolation(): void
    {
        $seeder = new IsolatedE2ESeeder;
        $seeder->run();

        $school1 = School::where('code', 'DEVTEST01')->firstOrFail();
        $school2 = School::where('code', 'DEVTEST02')->firstOrFail();

        $admin1 = Membership::where('school_id', $school1->id)->where('login_identifier', 'dev_admin')->firstOrFail();
        $admin2 = Membership::where('school_id', $school2->id)->where('login_identifier', 'dev_admin2')->firstOrFail();

        $service = app(AcademicCatalog::class);

        // Query School 1 sections
        $school1Sections = $service->listing($admin1, 'sections', [], true);
        $school1Ids = collect($school1Sections['items'])->pluck('id')->all();

        // Query School 2 sections
        $school2Sections = $service->listing($admin2, 'sections', [], true);
        $school2Ids = collect($school2Sections['items'])->pluck('id')->all();

        // School 1 must have 25 items on page 1, School 2 must have 1 item
        $this->assertCount(25, $school1Ids);
        $this->assertCount(1, $school2Ids);

        // Strict isolation: Zero overlap between School 1 and School 2 IDs
        $overlap = array_intersect($school1Ids, $school2Ids);
        $this->assertEmpty($overlap);
    }

    public function test_mfa_credentials_are_properly_encrypted_and_enrolled(): void
    {
        $seeder = new IsolatedE2ESeeder;
        $seeder->run();

        $school1 = School::where('code', 'DEVTEST01')->firstOrFail();
        $adminUser = User::where('name', 'Dev Admin')->firstOrFail();

        $credential = DB::table('mfa_credentials')->where('user_id', $adminUser->id)->first();

        $this->assertNotNull($credential);
        $this->assertNotNull($credential->confirmed_at);

        // Ensure the stored secret is encrypted (not plain text)
        $rawSeed = env('E2E_MFA_TOTP_SEED', 'JBSWY3DPEHPK3PXP');
        $this->assertNotSame($rawSeed, $credential->secret_encrypted);

        // Decrypt and verify matching original seed
        $decrypted = Crypt::decryptString($credential->secret_encrypted);
        $this->assertSame($rawSeed, $decrypted);
    }

    public function test_no_student_data_is_seeded(): void
    {
        $seeder = new IsolatedE2ESeeder;
        $seeder->run();

        // Enforce project policy: Zero student records or memberships
        $studentMemberships = Membership::whereIn('role', [Role::Student, Role::Parent])->count();
        $this->assertSame(0, $studentMemberships);

        // Confirm all memberships belong exclusively to school_admin or teacher
        $nonAdminTeacherCount = Membership::whereNotIn('role', [Role::SchoolAdmin, Role::Teacher])->count();
        $this->assertSame(0, $nonAdminTeacherCount);
    }

    public function test_credentials_are_hashed_and_not_stored_plaintext(): void
    {
        $seeder = new IsolatedE2ESeeder;
        $seeder->run();

        $adminUser = User::where('name', 'Dev Admin')->firstOrFail();
        $teacherUser = User::where('name', 'Dev Teacher')->firstOrFail();

        $adminPlain = env('E2E_ADMIN_PASSWORD', 'DevAdmin#2026!Sec');
        $teacherPlain = env('E2E_TEACHER_PASSWORD', 'DevTeach#2026!Sec');

        // Confirm passwords are not plaintext in DB
        $this->assertNotSame($adminPlain, $adminUser->password);
        $this->assertNotSame($teacherPlain, $teacherUser->password);

        // Confirm Hash::check succeeds
        $this->assertTrue(Hash::check($adminPlain, $adminUser->password));
        $this->assertTrue(Hash::check($teacherPlain, $teacherUser->password));
    }
}
