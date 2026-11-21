<?php

use App\Http\Controllers\AcademicCatalogController;
use App\Http\Controllers\FoundationController as C;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\SecurityInventoryController;
use App\Services\AcademicCatalog;
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
    Route::post('/registrations', [RegistrationController::class, 'store'])->middleware('throttle:5,10,registration-submit:');
    Route::get('/registrations/status', [RegistrationController::class, 'status'])->middleware('throttle:30,1,registration-status:');

    Route::post('/auth/login', [C::class, 'login'])->middleware('throttle:5,1');
    Route::middleware(['auth:sanctum', 'identity.active'])->group(function (): void {
        Route::get('/me', [C::class, 'me']);
        Route::post('/auth/logout', [C::class, 'logout']);
        Route::post('/auth/password', [C::class, 'password'])->middleware('throttle:5,1');
        Route::get('/platform/health', function (Request $r) {
            abort(403); // No approved platform MFA-aware issuer exists; tokens alone never suffice.

        });
        Route::middleware('tenant')->group(function (): void {
            Route::get('/security/mfa/status', [SecurityInventoryController::class, 'mfaStatus']);
            Route::post('/security/mfa/challenge', [SecurityInventoryController::class, 'mfaChallenge'])->middleware('throttle:5,1,device-mfa:');
            Route::get('/security/devices', [SecurityInventoryController::class, 'devices']);
            Route::post('/security/devices/{id}/revoke', [SecurityInventoryController::class, 'revokeDevice'])->middleware('throttle:10,1,device-revoke:');
            Route::get('/schools/{school}', [C::class, 'school']);
            Route::post('/accounts', [C::class, 'provision']);
            Route::get('/academic/{kind}', [AcademicCatalogController::class, 'index'])->name('device.academic.index')->whereIn('kind', AcademicCatalog::KINDS);
            Route::post('/academic/{kind}', [AcademicCatalogController::class, 'store'])->name('device.academic.store')->whereIn('kind', AcademicCatalog::KINDS)->middleware('throttle:20,1,academic-create:');
            Route::post('/storage/reservations', [C::class, 'fileDenied']);
            Route::post('/imports/files', [C::class, 'fileDenied']);
            Route::post('/ingestion/remote', [C::class, 'fileDenied']);
            Route::post('/ingestion/base64', [C::class, 'fileDenied']);
            Route::post('/homework/{homework}/attachments', [C::class, 'fileDenied']);
        });
    });
});
