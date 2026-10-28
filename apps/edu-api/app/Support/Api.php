<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

class Api
{
    public static function ok(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => ['request_id' => request()->attributes->get('request_id')]], $status)->header('X-Request-ID', request()->attributes->get('request_id'))->header('Cache-Control', 'private, no-store');
    }

    public static function error(string $code, string $message, int $status, array $fields = []): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message, 'fields' => (object) $fields], 'meta' => ['request_id' => request()->attributes->get('request_id')]], $status)->header('X-Request-ID', request()->attributes->get('request_id'))->header('Cache-Control', 'private, no-store');
    }
}
