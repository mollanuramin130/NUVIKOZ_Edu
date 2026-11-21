<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\School;
use App\Models\User;
use App\Services\PlatformAssurance;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PlatformRegistrationController extends Controller
{
    private const STATES = ['submitted', 'reviewing', 'approved', 'rejected'];

    private function authorizeReview(Request $request): void
    {
        abort_unless(app(PlatformAssurance::class)->allows($request, 'platform.registrations.review'), 403);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeReview($request);
        $rules = [
            'state' => ['sometimes', 'in:'.implode(',', self::STATES)],
            'search' => ['sometimes', 'string', 'min:2', 'max:80'],
            'cursor' => ['sometimes', 'ulid'],
        ];
        abort_unless(array_diff_key($request->query(), $rules) === [], 422);
        $filters = Validator::make($request->query(), $rules)->validate();
        $rows = DB::table('registration_requests')
            ->select(['id', 'organization_name', 'category', 'state', 'submitted_at'])
            ->when($filters['state'] ?? null, fn ($query, $state) => $query->where('state', $state))
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->whereRaw("organization_name LIKE ? ESCAPE '!'", ['%'.strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']).'%']))
            ->when($filters['cursor'] ?? null, fn ($query, $cursor) => $query->where('id', '<', $cursor))
            ->orderByDesc('id')->limit(26)->get();
        $page = $rows->take(25)->values();

        return Api::ok(['items' => $page, 'next_cursor' => $rows->count() > 25 ? $page->last()->id : null]);
    }

    public function show(Request $request, string $registration): JsonResponse
    {
        $this->authorizeReview($request);
        abort_unless($request->query() === [], 422);
        $record = DB::table('registration_requests')->where('id', $registration)->first([
            'id', 'organization_name', 'category', 'contact_name', 'contact', 'notice_version', 'state', 'submitted_at',
        ]);
        abort_unless($record, 404);
        $history = DB::table('registration_reviews')->where('registration_id', $registration)->orderBy('id')->get([
            'id', 'actor_user_id', 'from_state', 'to_state', 'note', 'created_at',
        ]);

        return Api::ok(['registration' => $record, 'history' => $history, 'provisioning' => $this->provisioning($registration)]);
    }

    private function provisioning(string $registration): ?array
    {
        $row = DB::table('school_provisionings')
            ->join('schools', 'schools.id', '=', 'school_provisionings.school_id')
            ->where('registration_id', $registration)
            ->first(['school_provisionings.school_id', 'schools.code', 'school_provisionings.created_at', 'school_provisionings.actor_user_id']);

        return $row ? (array) $row + ['state' => 'provisioned', 'activation_state' => 'awaiting_verified_delivery'] : null;
    }

    public function provision(Request $request, string $registration): JsonResponse
    {
        $this->authorizeReview($request);
        abort_unless($request->isJson(), 415);
        abort_if(strlen($request->getContent()) > 1024, 413);
        abort_unless($request->query() === [], 422);
        $input = $request->json()->all();
        abort_unless(array_keys($input) === ['command_id'], 422);
        $command = Validator::make($input, ['command_id' => ['required', 'uuid']])->validate();
        $actor = $request->user()->id;

        return DB::transaction(function () use ($request, $registration, $command, $actor): JsonResponse {
            User::whereKey($actor)->lockForUpdate()->firstOrFail();
            $this->authorizeReview($request);
            $record = DB::table('registration_requests')->where('id', $registration)->lockForUpdate()->first();
            abort_unless($record, 404);
            abort_unless($record->state === 'approved', 409);

            $previous = DB::table('school_provisionings')->where('registration_id', $registration)->first();
            if ($previous) {
                abort_unless($previous->actor_user_id === $actor && $previous->command_id === $command['command_id'], 409);

                return Api::ok(['provisioning' => $this->provisioning($registration), 'replayed' => true]);
            }

            $school = School::create([
                'code' => 'EDU-'.Str::ulid(),
                'name' => $record->organization_name,
                'data_stage' => 'demo',
                'access_state' => 'suspended',
            ]);
            $admin = User::create([
                'name' => $record->contact_name,
                'password' => bin2hex(random_bytes(32)),
                'status' => 'inactive',
                'activation_required' => true,
                'must_change_password' => true,
            ]);
            $member = Membership::create([
                'school_id' => $school->id,
                'user_id' => $admin->id,
                'role' => Role::SchoolAdmin,
                'login_identifier' => $admin->id,
                'status' => 'inactive',
            ]);

            DB::table('school_provisionings')->insert([
                'registration_id' => $registration,
                'school_id' => $school->id,
                'membership_id' => $member->id,
                'actor_user_id' => $actor,
                'command_id' => $command['command_id'],
                'created_at' => now(),
            ]);
            AuditEvent::create([
                'school_id' => $school->id,
                'actor_user_id' => $actor,
                'action' => 'platform.registration.provisioned',
                'resource_id' => $member->id,
                'request_id' => $request->attributes->get('request_id'),
                'details' => ['registration_id' => $registration],
            ]);

            return Api::ok(['provisioning' => $this->provisioning($registration), 'replayed' => false], 201);
        }, 3);
    }

    public function review(Request $request, string $registration): JsonResponse
    {
        $this->authorizeReview($request);
        abort_unless($request->isJson(), 415);
        abort_if(strlen($request->getContent()) > 8192, 413);
        abort_unless($request->query() === [], 422);
        $rules = [
            'state' => ['required', 'in:reviewing,approved,rejected'],
            'note' => ['required', 'string', 'max:1000'],
            'command_id' => ['required', 'uuid'],
        ];
        $input = $request->json()->all();
        abort_unless(array_diff_key($input, $rules) === [], 422);
        $command = Validator::make($input, $rules)->validate();
        $actor = $request->user()->id;

        return DB::transaction(function () use ($request, $registration, $command, $actor): JsonResponse {
            User::whereKey($actor)->lockForUpdate()->firstOrFail();
            $this->authorizeReview($request);
            $record = DB::table('registration_requests')->where('id', $registration)->lockForUpdate()->first(['id', 'state']);
            abort_unless($record, 404);
            $previous = DB::table('registration_reviews')->where('registration_id', $registration)->where('command_id', $command['command_id'])->first();
            if ($previous) {
                abort_unless($previous->actor_user_id === $actor && $previous->to_state === $command['state'] && $previous->note === $command['note'], 409);

                return Api::ok(['id' => $registration, 'state' => $record->state, 'review_id' => $previous->id, 'replayed' => true]);
            }
            $allowed = match ($record->state) {
                'submitted' => ['reviewing'],
                'reviewing' => ['approved', 'rejected'],
                default => [],
            };
            abort_unless(in_array($command['state'], $allowed, true), 409);
            $id = (string) Str::ulid();
            DB::table('registration_reviews')->insert([
                'id' => $id, 'registration_id' => $registration, 'actor_user_id' => $actor,
                'from_state' => $record->state, 'to_state' => $command['state'], 'note' => $command['note'],
                'command_id' => $command['command_id'], 'request_id' => (string) $request->attributes->get('request_id'),
                'created_at' => now(),
            ]);
            DB::table('registration_requests')->where('id', $registration)->update(['state' => $command['state']]);

            return Api::ok(['id' => $registration, 'state' => $command['state'], 'review_id' => $id, 'replayed' => false]);
        }, 3);
    }
}
