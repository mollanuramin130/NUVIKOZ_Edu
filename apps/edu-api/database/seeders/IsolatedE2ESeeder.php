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
     * Exact allowlist of approved disposable database names.
     */
    public const ALLOWED_DATABASE_NAMES = [
        'edu_test',
        'edu_e2e_disposable',
        'edu_dev_e2e',
    ];

    /**
     * Exact allowlist of approved database connection hosts.
     */
    public const ALLOWED_DATABASE_HOSTS = [
        '127.0.0.1',
        'localhost',
        'mariadb',
        'mysql',
        '::1',
    ];

    /**
     * Exact blacklist of disallowed database name prefixes or exact names.
     */
    public const DISALLOWED_DATABASE_NAMES = [
        'production',
        'prod',
        'nuvikoz',
        'nuvikoz_prod',
        'nuvikoz_production',
        'forge',
        'laravel',
        'shared',
    ];

    /**
     * Safety assertion ensuring the seeder never executes in production,
     * against non-disposable databases, or unauthorized connection hosts.
     *
     * @throws \RuntimeException
     */
    public static function assertEnvironmentSafe(): void
    {
        // 1. Explicit production check (fail closed)
        if (app()->isProduction() || app()->environment('production')) {
            throw new \RuntimeException('CRITICAL SECURITY VIOLATION: IsolatedE2ESeeder is prohibited in production.');
        }

        $isTesting = app()->environment('testing');
        $connection = config('database.default');
        $driver = config('database.connections.'.$connection.'.driver');
        $database = (string) config('database.connections.'.$connection.'.database');
        $host = (string) config('database.connections.'.$connection.'.host', '127.0.0.1');

        // 2. Narrowly scoped SQLite in-memory PHPUnit-only exception
        if ($isTesting && $driver === 'sqlite' && $database === ':memory:') {
            return;
        }

        // Reject all other SQLite connections (file paths, shared sqlite files, non-testing sqlite)
        if ($driver === 'sqlite') {
            throw new \RuntimeException("CRITICAL: SQLite connection '{$database}' is not an authorized in-memory test database.");
        }

        // 3. Supported relational drivers
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw new \RuntimeException("CRITICAL: Unsupported database driver '{$driver}'. Only mysql and mariadb are supported.");
        }

        // 4. Dedicated seeding opt-in token (mandatory for all non-in-memory-sqlite connections)
        $optIn = $_ENV['ALLOW_DISPOSABLE_E2E_SEEDING'] ?? $_SERVER['ALLOW_DISPOSABLE_E2E_SEEDING'] ?? getenv('ALLOW_DISPOSABLE_E2E_SEEDING') ?: env('ALLOW_DISPOSABLE_E2E_SEEDING');
        if ($optIn !== 'true') {
            throw new \RuntimeException('CRITICAL: Disposable E2E seeding is not authorized. ALLOW_DISPOSABLE_E2E_SEEDING=true is required.');
        }

        // 5. Explicitly authorized application environments
        $currentEnv = app()->environment();
        if (! in_array($currentEnv, ['testing', 'local', 'disposable_e2e', 'staging'], true)) {
            throw new \RuntimeException("CRITICAL: Environment '{$currentEnv}' is not authorized for disposable seeding.");
        }

        // 6. Database name validation - Exact allowlist & blacklist check
        if (empty($database)) {
            throw new \RuntimeException('CRITICAL: Database name is not configured.');
        }

        $lowerDb = strtolower($database);
        foreach (self::DISALLOWED_DATABASE_NAMES as $disallowed) {
            if ($lowerDb === $disallowed || str_starts_with($lowerDb, $disallowed.'_') || str_ends_with($lowerDb, '_'.$disallowed)) {
                throw new \RuntimeException("CRITICAL: Unsafe database name '{$database}' detected.");
            }
        }

        if (! in_array($lowerDb, self::ALLOWED_DATABASE_NAMES, true)) {
            throw new \RuntimeException("CRITICAL: Database '{$database}' is not an authorized disposable database identity.");
        }

        // 7. Resolved connection host validation
        $lowerHost = strtolower($host);
        if (! in_array($lowerHost, self::ALLOWED_DATABASE_HOSTS, true)) {
            throw new \RuntimeException("CRITICAL: Database host '{$host}' is not an authorized local or isolated container host.");
        }
    }

    /**
     * Preflight validation of securely injected environment credentials.
     * Prevents seeding before any database write occurs.
     *
     * @return array{admin_password: string, teacher_password: string, admin2_password: string, mfa_totp_seed: string}
     *
     * @throws \RuntimeException
     */
    public static function validateCredentialsPreflight(): array
    {
        $adminPassword = self::getEnvVar('E2E_ADMIN_PASSWORD');
        $teacherPassword = self::getEnvVar('E2E_TEACHER_PASSWORD');
        $admin2Password = self::getEnvVar('E2E_ADMIN2_PASSWORD');
        $totpSeed = self::getEnvVar('E2E_MFA_TOTP_SEED');

        self::validatePasswordFormat('E2E_ADMIN_PASSWORD', $adminPassword);
        self::validatePasswordFormat('E2E_TEACHER_PASSWORD', $teacherPassword);
        self::validatePasswordFormat('E2E_ADMIN2_PASSWORD', $admin2Password);
        self::validateTotpSeedFormat('E2E_MFA_TOTP_SEED', $totpSeed);

        return [
            'admin_password' => $adminPassword,
            'teacher_password' => $teacherPassword,
            'admin2_password' => $admin2Password,
            'mfa_totp_seed' => $totpSeed,
        ];
    }

    /**
     * Safely resolve environment variable from superglobals or env().
     */
    private static function getEnvVar(string $key): ?string
    {
        $val = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: env($key);
        if ($val === false || $val === null || trim((string) $val) === '') {
            return null;
        }

        return (string) $val;
    }

    /**
     * Validate password complexity without logging secret value.
     *
     * @throws \RuntimeException
     */
    private static function validatePasswordFormat(string $varName, ?string $password): void
    {
        if ($password === null || trim($password) === '') {
            throw new \RuntimeException("CRITICAL: {$varName} is missing. Secure environment injection is required.");
        }

        if (strlen($password) < 12) {
            throw new \RuntimeException("CRITICAL: {$varName} is too short. Minimum 12 characters required.");
        }

        $hasUpper = (bool) preg_match('/[A-Z]/', $password);
        $hasLower = (bool) preg_match('/[a-z]/', $password);
        $hasDigit = (bool) preg_match('/[0-9]/', $password);
        $hasSpecial = (bool) preg_match('/[^A-Za-z0-9]/', $password);

        if (! $hasUpper || ! $hasLower || ! $hasDigit || ! $hasSpecial) {
            throw new \RuntimeException("CRITICAL: {$varName} does not meet complexity requirements (must contain uppercase, lowercase, digit, and special character).");
        }
    }

    /**
     * Validate TOTP seed Base32 format without logging secret value.
     *
     * @throws \RuntimeException
     */
    private static function validateTotpSeedFormat(string $varName, ?string $seed): void
    {
        if ($seed === null || trim($seed) === '') {
            throw new \RuntimeException("CRITICAL: {$varName} is missing. Secure environment injection is required.");
        }

        if (! preg_match('/^[A-Z2-7]{16,64}$/', $seed)) {
            throw new \RuntimeException("CRITICAL: {$varName} has invalid Base32 format (must be 16-64 characters matching [A-Z2-7]).");
        }
    }

    /**
     * Execute the synthetic database seeding.
     */
    public function run(): void
    {
        self::assertEnvironmentSafe();
        $credentials = self::validateCredentialsPreflight();

        DB::transaction(function () use ($credentials): void {
            $this->seedSchoolOne($credentials);
            $this->seedSchoolTwo($credentials);
        });
    }

    /**
     * Seed or verify ownership of a synthetic School.
     *
     * @throws \RuntimeException
     */
    private function seedSchool(string $code, string $name): School
    {
        $school = School::where('code', $code)->first();
        if ($school) {
            if ($school->name !== $name) {
                throw new \RuntimeException("CRITICAL: Existing school with code '{$code}' has unexpected name '{$school->name}'. Fixture ownership cannot be verified.");
            }

            $school->update([
                'data_stage' => 'live',
                'access_state' => 'live_enabled',
                'entitlement_ends_at' => CarbonImmutable::parse('2028-12-31 23:59:59'),
                'settings' => ['timezone' => 'Asia/Kolkata'],
            ]);

            return $school;
        }

        return School::create([
            'code' => $code,
            'name' => $name,
            'data_stage' => 'live',
            'access_state' => 'live_enabled',
            'entitlement_ends_at' => CarbonImmutable::parse('2028-12-31 23:59:59'),
            'settings' => ['timezone' => 'Asia/Kolkata'],
            'version' => 1,
        ]);
    }

    /**
     * Seed or verify ownership of a synthetic User and Membership.
     *
     * @return array{0: User, 1: Membership}
     *
     * @throws \RuntimeException
     */
    private function seedUserAndMembership(
        School $school,
        string $loginIdentifier,
        string $expectedUserName,
        Role $role,
        string $password
    ): array {
        // 1. Check existing membership in this school
        $existingMembership = Membership::where('school_id', $school->id)
            ->where('login_identifier', $loginIdentifier)
            ->first();

        if ($existingMembership) {
            $user = User::find($existingMembership->user_id);
            if (! $user) {
                throw new \RuntimeException("CRITICAL: Membership '{$loginIdentifier}' references non-existent user '{$existingMembership->user_id}'.");
            }
            if ($user->name !== $expectedUserName) {
                throw new \RuntimeException("CRITICAL: Membership '{$loginIdentifier}' belongs to user '{$user->name}', expected '{$expectedUserName}'. Fixture ownership verification failed.");
            }
            if ($user->is_platform_admin) {
                throw new \RuntimeException("CRITICAL: User '{$user->name}' is a platform admin; cannot be reused as synthetic school user.");
            }

            $user->password = $password;
            $user->save();

            if ($existingMembership->role !== $role) {
                throw new \RuntimeException("CRITICAL: Membership '{$loginIdentifier}' has role '{$existingMembership->role->value}', expected '{$role->value}'.");
            }

            return [$user, $existingMembership];
        }

        // 2. Membership does not exist yet. Check if a user with $expectedUserName already exists
        $existingUser = User::where('name', $expectedUserName)->first();
        if ($existingUser) {
            if ($existingUser->is_platform_admin) {
                throw new \RuntimeException("CRITICAL: Existing user '{$expectedUserName}' is a platform admin; refusing takeover.");
            }

            $otherMembershipsCount = Membership::where('user_id', $existingUser->id)
                ->where('school_id', '!=', $school->id)
                ->count();

            if ($otherMembershipsCount > 0) {
                throw new \RuntimeException("CRITICAL: Existing user '{$expectedUserName}' belongs to other school tenants; refusing takeover.");
            }

            $existingUser->password = $password;
            $existingUser->save();
            $user = $existingUser;
        } else {
            $user = User::create([
                'name' => $expectedUserName,
                'password' => $password,
                'status' => 'active',
                'is_platform_admin' => false,
                'must_change_password' => false,
                'activation_required' => false,
            ]);
        }

        $membership = Membership::create([
            'school_id' => $school->id,
            'user_id' => $user->id,
            'role' => $role,
            'login_identifier' => $loginIdentifier,
            'status' => 'active',
        ]);

        return [$user, $membership];
    }

    /**
     * Seed School 1 (DEVTEST01) with full catalog hierarchy and 26 sections for cursor pagination.
     *
     * @param  array{admin_password: string, teacher_password: string, admin2_password: string, mfa_totp_seed: string}  $credentials
     */
    private function seedSchoolOne(array $credentials): void
    {
        // 1. School 1
        $school = $this->seedSchool('DEVTEST01', 'Staging Test Academy 01');

        // 2. School Admin (dev_admin)
        [$adminUser] = $this->seedUserAndMembership(
            $school,
            'dev_admin',
            'Dev Admin',
            Role::SchoolAdmin,
            $credentials['admin_password']
        );

        // Step-Up MFA Enrollment for Admin
        $existingMfa = DB::table('mfa_credentials')->where('user_id', $adminUser->id)->first();
        if ($existingMfa) {
            DB::table('mfa_credentials')
                ->where('user_id', $adminUser->id)
                ->update([
                    'secret_encrypted' => Crypt::encryptString($credentials['mfa_totp_seed']),
                    'confirmed_at' => now(),
                    'updated_at' => now(),
                ]);
        } else {
            DB::table('mfa_credentials')->insert([
                'user_id' => $adminUser->id,
                'secret_encrypted' => Crypt::encryptString($credentials['mfa_totp_seed']),
                'version' => (string) Str::ulid(),
                'confirmed_at' => now(),
                'last_counter' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 3. Teacher (dev_teacher)
        $this->seedUserAndMembership(
            $school,
            'dev_teacher',
            'Dev Teacher',
            Role::Teacher,
            $credentials['teacher_password']
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
            if ($activeSession->starts_on !== '2026-06-01' || $activeSession->ends_on !== '2027-05-31') {
                throw new \RuntimeException('CRITICAL: Existing active session has conflicting dates; ownership verification failed.');
            }
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
        } else {
            if ($futureSession->starts_on !== '2027-06-01' || $futureSession->ends_on !== '2028-05-31') {
                throw new \RuntimeException('CRITICAL: Existing future session has conflicting dates; ownership verification failed.');
            }
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
                if ($class->grade_label !== $def['grade_label'] || (int) $class->sort_order !== $def['sort_order']) {
                    $className = $def['name'];
                    throw new \RuntimeException("CRITICAL: Existing class '{$className}' has conflicting attributes; ownership verification failed.");
                }
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
        $grade10OfferingId = $offeringIds['Grade 10'];
        $baseTimestamp = CarbonImmutable::parse('2026-01-01 10:00:00');

        for ($i = 0; $i < 26; $i++) {
            $sectionLetter = chr(65 + $i);
            $sectionName = 'Section '.$sectionLetter;

            $existingSection = DB::table('sections')
                ->where('school_id', $school->id)
                ->where('class_offering_id', $grade10OfferingId)
                ->where('name', $sectionName)
                ->first();

            if (! $existingSection) {
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
                if ($subject->name !== $sDef['name']) {
                    $subCode = $sDef['code'];
                    throw new \RuntimeException("CRITICAL: Existing subject '{$subCode}' has conflicting name '{$subject->name}'; ownership verification failed.");
                }
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
            } else {
                if ((bool) $subOffering->compulsory !== $compulsory) {
                    throw new \RuntimeException('CRITICAL: Existing subject offering has conflicting compulsory flag; ownership verification failed.');
                }
            }
        }
    }

    /**
     * Seed School 2 (DEVTEST02) with independent catalog records to verify tenant isolation.
     *
     * @param  array{admin_password: string, teacher_password: string, admin2_password: string, mfa_totp_seed: string}  $credentials
     */
    private function seedSchoolTwo(array $credentials): void
    {
        // 1. School 2
        $school = $this->seedSchool('DEVTEST02', 'Staging Test Academy 02');

        // 2. School 2 Admin
        $this->seedUserAndMembership(
            $school,
            'dev_admin2',
            'Dev Admin 02',
            Role::SchoolAdmin,
            $credentials['admin2_password']
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
