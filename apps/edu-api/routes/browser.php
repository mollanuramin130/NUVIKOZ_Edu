<?php

use App\Http\Controllers\AcademicCatalogController;
use App\Http\Controllers\ActivationController;
use App\Http\Controllers\BrowserAuthController as Browser;
use App\Http\Controllers\CapabilityController;
use App\Http\Controllers\DemoSampleController;
use App\Http\Controllers\MfaController;
use App\Http\Controllers\MfaReplacementController;
use App\Http\Controllers\PlatformBrowserController;
use App\Http\Controllers\PlatformRegistrationController;
use App\Http\Controllers\RecoveryController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\SecurityInventoryController;
use App\Http\Middleware\BrowserSession;
use App\Http\Middleware\PlatformBrowser;
use App\Services\AcademicCatalog;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/browser')->group(function (): void {
    Route::get('/csrf', [Browser::class, 'bootstrap'])->middleware('throttle:30,1,browser-csrf:');
    Route::post('/login', [Browser::class, 'login'])->middleware('throttle:5,1,browser-login:');
    Route::post('/logout', [Browser::class, 'logout']);
    Route::get('/me', [Browser::class, 'me'])->middleware(['auth:sanctum', BrowserSession::class]);
});

Route::prefix('api/v1/browser')->group(function (): void {
    Route::post('/activation/consume', [ActivationController::class, 'consume'])->middleware('throttle:5,1,activation-consume:');
    Route::middleware(['auth:sanctum', BrowserSession::class])->group(function (): void {
        Route::post('/accounts', [ActivationController::class, 'provision']);
        Route::post('/accounts/{membership}/activation/reissue', [ActivationController::class, 'reissue'])->middleware('throttle:5,1,activation-reissue:');
    });
});

Route::prefix('api/v1/browser')->group(function (): void {
    Route::post('/recovery/request', [RecoveryController::class, 'request'])->middleware('throttle:5,1,recovery-request:');
    Route::post('/recovery/consume', [RecoveryController::class, 'consume'])->middleware('throttle:5,1,recovery-consume:');
    Route::post('/accounts/{membership}/recovery', [RecoveryController::class, 'issue'])->middleware(['auth:sanctum', BrowserSession::class, 'throttle:5,1,recovery-assisted:']);
});

Route::prefix('api/v1/browser/security/mfa')->middleware(['auth:sanctum', BrowserSession::class])->group(function (): void {
    Route::get('/status', [MfaController::class, 'status']);
    Route::post('/enrollment', [MfaController::class, 'enroll']);
    Route::post('/enrollment/confirm', [MfaController::class, 'confirm']);
    Route::post('/challenge', [MfaController::class, 'challenge']);
    Route::post('/recovery-code', [MfaController::class, 'recovery']);
    Route::post('/recovery-codes/regenerate', [MfaController::class, 'regenerate']);
});

Route::prefix('api/v1/browser/security')->middleware(['auth:sanctum', BrowserSession::class])->group(function (): void {
    Route::get('/sessions', [SecurityInventoryController::class, 'sessions']);
    Route::get('/devices', [SecurityInventoryController::class, 'devices']);
    Route::post('/sessions/{id}/revoke', [SecurityInventoryController::class, 'revokeSession'])->middleware('throttle:10,1,session-revoke:');
    Route::post('/devices/{id}/revoke', [SecurityInventoryController::class, 'revokeDevice'])->middleware('throttle:10,1,device-revoke:');
});

Route::prefix('api/v1/browser/security')->middleware(['auth:sanctum', BrowserSession::class])->group(function (): void {
    Route::get('/permissions', [CapabilityController::class, 'index']);
    Route::post('/permissions/{membership}', [CapabilityController::class, 'assign'])->middleware('throttle:10,1,permission-change:');
    Route::get('/capabilities/{capability}/probe', [CapabilityController::class, 'probe']);
});

// Browser-only academic setup; existing boundary/session middleware and service MFA both apply.
Route::prefix('api/v1/browser/academic')->middleware(['auth:sanctum', BrowserSession::class])->group(function (): void {
    Route::get('/{kind}', [AcademicCatalogController::class, 'index'])->whereIn('kind', AcademicCatalog::KINDS);
    Route::post('/{kind}', [AcademicCatalogController::class, 'store'])->whereIn('kind', AcademicCatalog::KINDS)->middleware('throttle:20,1,academic-create:');
});

Route::prefix('api/v1/browser/samples')->middleware(['auth:sanctum', BrowserSession::class, 'throttle:30,1,demo-samples:'])->group(function (): void {
    Route::get('/', [DemoSampleController::class, 'index']);
    Route::get('/{id}/preview', [DemoSampleController::class, 'preview'])->whereUlid('id');
});

Route::prefix('api/v1/browser/registration')->group(function (): void {
    Route::get('/notice', [RegistrationController::class, 'notice'])->middleware('throttle:30,1,browser-registration-notice:');
    Route::post('/submit', [RegistrationController::class, 'submitBrowser'])->middleware('throttle:5,10,registration-submit:');
    Route::post('/status', [RegistrationController::class, 'statusBrowser'])->middleware('throttle:30,1,registration-status:');
});

Route::prefix('api/v1/browser/platform')->middleware(PlatformBrowser::class)->group(function (): void {
    Route::post('/login', [PlatformBrowserController::class, 'login'])->middleware('throttle:5,1,platform-login:');
    Route::post('/logout', [PlatformBrowserController::class, 'logout']);
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/status', [PlatformBrowserController::class, 'status']);
        Route::get('/identity', [PlatformBrowserController::class, 'identity']);
        Route::get('/registrations', [PlatformRegistrationController::class, 'index'])->middleware('throttle:60,1,platform-registration-list:');
        Route::get('/registrations/{registration}', [PlatformRegistrationController::class, 'show'])->whereUlid('registration')->middleware('throttle:60,1,platform-registration-show:');
        Route::post('/registrations/{registration}/review', [PlatformRegistrationController::class, 'review'])->whereUlid('registration')->middleware('throttle:20,1,platform-registration-review:');
        Route::post('/registrations/{registration}/provision', [PlatformRegistrationController::class, 'provision'])->whereUlid('registration')->middleware('throttle:20,1,platform-registration-provision:');
        Route::post('/registrations/{registration}/entitlement', [PlatformRegistrationController::class, 'entitlement'])->whereUlid('registration')->middleware('throttle:20,1,platform-school-entitlement:');
        Route::post('/registrations/{registration}/activation/{operation}', [PlatformRegistrationController::class, 'activation'])
            ->whereUlid('registration')->whereIn('operation', ['issue', 'reissue', 'revoke'])->middleware('throttle:10,1,platform-school-activation:');
        Route::post('/mfa/enrollment', [PlatformBrowserController::class, 'enroll']);
        Route::post('/mfa/confirm', [PlatformBrowserController::class, 'confirm']);
        Route::post('/mfa/challenge', [PlatformBrowserController::class, 'challenge']);
        Route::post('/mfa/recovery', [PlatformBrowserController::class, 'recovery']);
    });
});

Route::prefix('api/v1/browser/security/mfa/replacement')->middleware(['auth:sanctum', BrowserSession::class])->group(function (): void {
    Route::post('/start', [MfaReplacementController::class, 'start']);
    Route::post('/confirm', [MfaReplacementController::class, 'confirm']);
});
Route::prefix('api/v1/browser/platform/mfa/replacement')->middleware([PlatformBrowser::class, 'auth:sanctum'])->group(function (): void {
    Route::post('/start', [MfaReplacementController::class, 'start']);
    Route::post('/confirm', [MfaReplacementController::class, 'confirm']);
});
