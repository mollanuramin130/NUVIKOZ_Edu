<?php

namespace App\Http\Controllers;

use App\Services\AcademicSessionChanges;
use App\Services\PlatformSessionReview;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AcademicSessionChangeController extends Controller
{
    private function input(Request $request, array $rules): array
    {
        abort_unless($request->isJson(), 415);
        abort_if(strlen($request->getContent()) > 8192, 413);
        $input = $request->json()->all();
        abort_unless($request->query() === [] && array_diff_key($input, $rules) === [], 422);
        abort_unless(is_int($input['version'] ?? null), 422);

        return Validator::make($input, $rules + ['version' => ['required', 'integer', 'min:1']])->validate();
    }

    private function key(Request $request): string
    {
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && preg_match('/\A[A-Za-z0-9_-]{16,160}\z/', $key), 422);

        return $key;
    }

    public function submit(Request $request, string $id, AcademicSessionChanges $changes): JsonResponse
    {
        $data = $this->input($request, [
            'proposed_start' => ['required', 'date_format:Y-m-d'],
            'proposed_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:proposed_start'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'version' => ['required', 'integer', 'min:1'],
        ]);

        return Api::ok($changes->submit($request->attributes->get('membership'), $id, $data, $this->key($request), $request->attributes->get('request_id')));
    }

    public function queue(Request $request, PlatformSessionReview $review): JsonResponse
    {
        $input = $request->query();
        $rules = ['cursor' => ['sometimes', 'required', 'string', 'max:2048'],
            'limit' => ['sometimes', 'required', 'integer', 'min:1', 'max:50'],
            'school_id' => ['sometimes', 'required', 'ulid'],
            'q' => ['sometimes', 'required', 'string', 'min:2', 'max:80']];
        abort_unless(array_diff_key($input, $rules) === [], 422);
        if (is_string($input['q'] ?? null)) {
            $input['q'] = trim($input['q']);
        }

        return Api::ok($review->queue($request, Validator::make($input, $rules)->validate()));
    }

    public function preview(Request $request, string $id, PlatformSessionReview $review): JsonResponse
    {
        abort_unless($request->query() === [], 422);

        return Api::ok($review->preview($request, $id));
    }

    public function review(Request $request, string $id, AcademicSessionChanges $changes): JsonResponse
    {
        $data = $this->input($request, [
            'decision' => ['required', 'in:approve,reject'],
            'preview_token' => ['sometimes', 'required', 'string', 'max:2048', 'prohibited_if:decision,reject'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'version' => ['required', 'integer', 'min:1'],
        ]);

        return Api::ok($changes->review($request, $id, $data, $this->key($request)));
    }
}
