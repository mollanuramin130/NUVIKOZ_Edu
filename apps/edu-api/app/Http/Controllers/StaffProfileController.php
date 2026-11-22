<?php

namespace App\Http\Controllers;

use App\Models\Membership;
use App\Services\SchoolAccess;
use App\Services\StaffProfiles;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

class StaffProfileController extends Controller
{
    private function limited(Request $request, callable $action, bool $write = false): JsonResponse
    {
        $actor = $request->attributes->get('membership');
        app(SchoolAccess::class)->requireAdmin($actor);
        $bucket = ($write ? 'staff-write:' : 'staff-read:').$actor->school_id.':'.$actor->user_id;
        $result = RateLimiter::attempt($bucket, $write ? 20 : 60, function () use ($action, $actor) {
            // Count rejected inputs and domain conflicts as requests too.
            try {
                return $action($actor);
            } catch (\Throwable $e) {
                return $e;
            }
        }, 60);
        if ($result instanceof \Throwable) {
            throw $result;
        }
        abort_if($result === false, 429, '', ['Retry-After' => (string) RateLimiter::availableIn($bucket)]);

        return $result;
    }

    private function filters(Request $request): array
    {
        $input = $request->query();
        abort_unless(array_diff(array_keys($input), ['cursor', 'q']) === [], 422);
        if (is_string($input['q'] ?? null)) {
            $input['q'] = trim($input['q']);
        }

        return Validator::make($input, ['cursor' => 'sometimes|required|ulid', 'q' => 'sometimes|required|string|min:2|max:80'])->validate();
    }

    public function index(Request $request, StaffProfiles $staff): JsonResponse
    {
        return $this->limited($request, fn (Membership $actor) => Api::ok($staff->listing($actor, $this->filters($request), false)));
    }

    public function candidates(Request $request, StaffProfiles $staff): JsonResponse
    {
        return $this->limited($request, fn (Membership $actor) => Api::ok($staff->listing($actor, $this->filters($request), true)));
    }

    public function show(Request $request, StaffProfiles $staff, string $id): JsonResponse
    {
        return $this->limited($request, function (Membership $actor) use ($request, $staff, $id) {
            abort_if($request->query() !== [], 422);

            return Api::ok($staff->detail($actor, $id));
        });
    }

    public function store(Request $request, StaffProfiles $staff): JsonResponse
    {
        return $this->limited($request, function (Membership $actor) use ($request, $staff) {
            abort_unless($request->isJson(), 415);
            $input = $request->json()->all();
            abort_unless($request->query() === [] && array_diff(array_keys($input), ['membership_id']) === [], 422);
            $data = Validator::make($input, ['membership_id' => 'required|ulid'])->validate();
            $key = $request->header('Idempotency-Key');
            abort_unless(is_string($key) && preg_match('/\A[A-Za-z0-9_-]{16,160}\z/', $key), 422);
            $result = $staff->register($actor, $data['membership_id'], $key, $request->attributes->get('request_id'));

            return Api::ok($result, $result['replayed'] ? 200 : 201);
        }, true);
    }
}
