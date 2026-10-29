<?php

use App\Http\Controllers\FoundationController as C;
use App\Support\Api;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', fn () => Api::ok(['status' => 'ok', 'api_version' => 'v1']));
    Route::get('/health/ready', function () {
        try {
            DB::select('select 1');

            return Api::ok(['status' => 'ready']);
        } catch (Throwable $e) {
            return Api::error('NOT_READY', 'Database unavailable.', 503);
        }
    });
    Route::post('/auth/login', [C::class, 'login'])->middleware('throttle:5,1');
    Route::middleware(['auth:sanctum', 'identity.active'])->group(function (): void {
        Route::get('/me', [C::class, 'me']);
        Route::post('/auth/logout', [C::class, 'logout']);
        Route::post('/auth/password', [C::class, 'password'])->middleware('throttle:5,1');
        Route::get('/platform/health', function (Request $r) {
            abort(403); // No approved platform MFA-aware issuer exists; tokens alone never suffice.

        });
        Route::middleware('tenant')->group(function (): void {
            Route::get('/schools/{school}', [C::class, 'school']);
            Route::post('/accounts', [C::class, 'provision']);
            Route::post('/storage/reservations', [C::class, 'fileDenied']);
            Route::post('/imports/files', [C::class, 'fileDenied']);
            Route::post('/ingestion/remote', [C::class, 'fileDenied']);
            Route::post('/ingestion/base64', [C::class, 'fileDenied']);
            Route::post('/homework/{homework}/attachments', [C::class, 'fileDenied']);
        });
    });
});
