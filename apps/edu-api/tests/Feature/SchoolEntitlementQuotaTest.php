<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Services\SchoolEntitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SchoolEntitlementQuotaTest extends TestCase
{
    use RefreshDatabase;

    public function test_free_quota_boundaries_and_local_day_with_tenant_isolation(): void
    {
        $this->travelTo(Carbon::parse('2026-11-21 18:29:59 UTC'));
        $actor = User::create(['name' => 'Synthetic actor', 'password' => Str::random(32)]);
        $school = $this->freeSchool('QUOTA-A', $actor);
        $other = $this->freeSchool('QUOTA-B', $actor);
        $service = app(SchoolEntitlements::class);
        $document = (string) Str::ulid();
        $admit = fn (string $doc, int $bytes, ?string $key = null) => $service->admitPdf($school->id, $doc, $bytes, $key ?? (string) Str::uuid(), $actor->id, 'quota-test');
        $deny = function (callable $command, int $status): void {
            try {
                $command();
                $this->fail('Quota admitted a rejected command.');
            } catch (HttpException $error) {
                $this->assertSame($status, $error->getStatusCode());
            }
        };
        $deny(fn () => $admit($document, 5_000_001), 422);
        $key = (string) Str::uuid();
        $this->assertFalse($admit($document, 5_000_000, $key)['replayed']);
        $this->assertTrue($admit($document, 5_000_000, $key)['replayed']);
        $deny(fn () => $admit($document, 1, $key), 409);
        for ($i = 1; $i < 10; $i++) {
            $admit((string) Str::ulid(), 5_000_000);
        }
        $deny(fn () => $admit($document, 1), 409);
        $this->assertSame('2026-11-21', $service->summary($school)['quota']['school_date']);
        $this->travel(1)->seconds();
        $this->assertSame('2026-11-22', $service->summary($school)['quota']['school_date']);
        $this->assertSame(0, $service->summary($school)['quota']['daily']);
        for ($i = 10; $i < 100; $i++) {
            if ($i > 10 && $i % 10 === 0) {
                $this->travel(1)->days();
            }
            if ($i === 79) {
                $this->assertFalse($service->summary($school)['quota']['warning']);
            }
            if ($i === 50) {
                $deny(fn () => $admit((string) Str::ulid(), 1), 409);
            }
            $admit($i < 50 ? (string) Str::ulid() : $document, 5_000_000);
            if ($i === 79) {
                $this->assertTrue($service->summary($school)['quota']['warning']);
            }
        }
        $this->travel(1)->days();
        $quota = $service->summary($school)['quota'];
        $this->assertSame(500_000_000, $quota['bytes']);
        $this->assertSame(50, $quota['active']);
        $this->assertTrue($quota['storage_blocked']);
        $deny(fn () => $admit($document, 1), 409);
        $this->assertSame(0, $service->summary($other)['quota']['bytes']);
        $outsider = User::create(['name' => 'Other synthetic actor', 'password' => Str::random(32)]);
        $deny(fn () => $service->admitPdf($school->id, $document, 1, (string) Str::uuid(), $outsider->id, 'quota-test'), 403);
        $school->update(['access_state' => 'suspended']);
        $deny(fn () => $admit($document, 1), 403);
    }

    private function freeSchool(string $code, User $actor): School
    {
        $school = School::create(['code' => $code, 'name' => $code, 'data_stage' => 'live', 'access_state' => 'live_enabled', 'entitlement_ends_at' => now()->addDays(30), 'settings' => ['timezone' => 'Asia/Kolkata']]);
        Membership::create(['school_id' => $school->id, 'user_id' => $actor->id, 'role' => 'school_admin', 'login_identifier' => 'ADMIN', 'status' => 'active']);
        DB::table('school_entitlement_events')->insert([
            'id' => (string) Str::ulid(), 'school_id' => $school->id, 'actor_id' => $actor->id,
            'command_id' => (string) Str::uuid(), 'sequence' => 1, 'action' => 'grant_free_live',
            'policy' => 'free-live-v1', 'term_days' => 30, 'starts_at' => now(), 'ends_at' => now()->addDays(30),
        ]);

        return $school;
    }
}
