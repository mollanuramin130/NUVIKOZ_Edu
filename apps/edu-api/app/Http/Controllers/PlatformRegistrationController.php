<?php

namespace App\Http\Controllers;

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

        return Api::ok(['registration' => $record, 'history' => $history]);
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
