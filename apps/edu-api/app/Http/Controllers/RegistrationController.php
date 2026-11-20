<?php

namespace App\Http\Controllers;

use App\Support\Api;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RegistrationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $noticeVersion = $this->requireIntake($request);
        abort_unless($request->isJson(), 415);
        abort_unless($request->query() === [], 422);
        abort_if(strlen($request->getContent()) > 4096, 413);

        $rules = [
            'organization_name' => ['required', 'string', 'min:2', 'max:160'],
            'category' => ['required', 'string', 'max:40'],
            'contact_name' => ['required', 'string', 'max:100'],
            'contact' => ['required', 'string', 'max:160'],
            'notice_version' => ['required', 'string', Rule::in([$noticeVersion])],
        ];
        $input = $request->json()->all();
        abort_unless(array_diff_key($input, $rules) === [], 422);
        $data = Validator::make($input, $rules)->validate();
        $contact = $data['contact'];
        if (filter_var($contact, FILTER_VALIDATE_EMAIL)) {
            $contact = Str::lower($contact);
        } elseif (! preg_match('/\A\+[1-9][0-9]{7,14}\z/', $contact)) {
            throw ValidationException::withMessages(['contact' => 'Enter an email address or an international phone number.']);
        }

        $id = (string) Str::ulid();
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $duplicateKey = hash('sha256', json_encode([
            Str::lower(Str::squish($data['organization_name'])),
            Str::lower(Str::squish($data['category'])),
            $contact,
        ], JSON_THROW_ON_ERROR));

        try {
            DB::table('registration_requests')->insert(array_replace($data, [
                'id' => $id,
                'tracking_hash' => hash('sha256', $token),
                'duplicate_key' => $duplicateKey,
                'contact' => $contact,
                'submitted_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            return Api::error('REGISTRATION_CONFLICT', 'The application could not be submitted. Contact support if you need assistance.', 409);
        } catch (QueryException) {
            return Api::error('REGISTRATION_UNAVAILABLE', 'Registration is temporarily unavailable.', 503);
        }

        return Api::ok($this->projection($id, 'submitted') + ['tracking_token' => $token], 201);
    }

    public function status(Request $request): JsonResponse
    {
        $this->requireIntake($request);
        abort_unless($request->query() === [], 422);
        $token = $request->header('X-Registration-Token');
        abort_unless(is_string($token) && preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token), 404);

        $record = DB::table('registration_requests')
            ->where('tracking_hash', hash('sha256', $token))
            ->first(['id', 'state']);
        abort_unless($record, 404);

        return Api::ok($this->projection($record->id, $record->state));
    }

    private function requireIntake(Request $request): string
    {
        $version = config('registration.notice_version');
        abort_unless(config('registration.enabled') === true && is_string($version) && preg_match('/\A[A-Za-z0-9._-]{1,32}\z/', $version), 404);
        abort_unless($request->isSecure(), 403);

        return $version;
    }

    private function projection(string $id, string $state): array
    {
        return [
            'id' => $id,
            'state' => $state,
            'support_contact' => (string) config('registration.support_contact'),
            'demo_available' => false,
        ];
    }
}
