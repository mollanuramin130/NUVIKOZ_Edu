<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RegistrationIntakeTest extends TestCase
{
    use RefreshDatabase;

    private const SUBMIT = 'https://localhost/api/v1/registrations';

    private const STATUS = 'https://localhost/api/v1/registrations/status';

    private const APPLICATION = [
        'organization_name' => 'Synthetic School',
        'category' => 'School',
        'contact_name' => 'Synthetic Applicant',
        'contact' => 'Applicant@example.test',
        'notice_version' => 'test-v1',
    ];

    public function test_intake_is_disabled_by_default_and_requires_https_when_enabled(): void
    {
        $this->postJson(self::SUBMIT, self::APPLICATION)->assertNotFound();
        $this->getJson(self::STATUS)->assertNotFound();

        $this->enableIntake();
        $this->postJson('http://localhost/api/v1/registrations', self::APPLICATION)->assertForbidden();
        $this->assertDatabaseCount('registration_requests', 0);
    }

    public function test_submission_returns_a_secret_once_and_status_excludes_applicant_details(): void
    {
        $this->enableIntake();
        $response = $this->postJson(self::SUBMIT, self::APPLICATION)->assertCreated();
        $token = $response->json('data.tracking_token');
        $id = $response->json('data.id');

        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $token);
        $this->assertDatabaseHas('registration_requests', [
            'id' => $id,
            'tracking_hash' => hash('sha256', $token),
            'contact' => 'applicant@example.test',
            'state' => 'submitted',
        ]);
        $this->assertDatabaseMissing('registration_requests', ['tracking_hash' => $token]);
        $this->assertSame(0, DB::table('schools')->count());

        $status = $this->getJson(self::STATUS, ['X-Registration-Token' => $token])->assertOk();
        $this->assertStringContainsString('no-store', $status->headers->get('Cache-Control'));
        $this->assertSame([
            'id' => $id,
            'state' => 'submitted',
            'support_contact' => '+919876543210',
            'demo_available' => false,
        ], $status->json('data'));
        $this->getJson(self::STATUS)->assertNotFound();
        $this->getJson(self::STATUS, ['X-Registration-Token' => str_repeat('A', 43)])->assertNotFound();
        $this->getJson(self::STATUS.'?tracking_token='.$token)->assertUnprocessable();
    }

    public function test_invalid_and_duplicate_applications_do_not_create_extra_rows(): void
    {
        $this->enableIntake();
        $this->postJson(self::SUBMIT, array_replace(self::APPLICATION, ['notice_version' => 'old']))->assertUnprocessable();
        $this->postJson(self::SUBMIT, array_replace(self::APPLICATION, ['contact' => 'not a contact']))->assertUnprocessable();
        $this->postJson(self::SUBMIT, self::APPLICATION + ['state' => 'approved'])->assertUnprocessable();
        $this->postJson(self::SUBMIT, self::APPLICATION)->assertCreated();
        $duplicate = $this->postJson(self::SUBMIT, array_replace(self::APPLICATION, [
            'organization_name' => 'SYNTHETIC   school',
            'category' => 'school',
            'contact' => 'applicant@example.test',
        ]))->assertConflict();
        $duplicate->assertJsonMissingPath('data.tracking_token');
        $this->postJson(self::SUBMIT, self::APPLICATION)->assertTooManyRequests();
        $this->assertDatabaseCount('registration_requests', 1);
    }

    private function enableIntake(): void
    {
        config([
            'registration.enabled' => true,
            'registration.notice_version' => 'test-v1',
            'registration.support_contact' => '+919876543210',
        ]);
    }
}
