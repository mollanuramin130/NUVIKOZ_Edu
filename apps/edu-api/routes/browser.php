<?php

use App\Http\Controllers\BrowserAuthController as Browser;
use App\Http\Middleware\BrowserSession;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/browser')->group(function (): void {
    Route::get('/csrf', [Browser::class, 'bootstrap'])->middleware('throttle:30,1,browser-csrf:');
    Route::post('/login', [Browser::class, 'login'])->middleware('throttle:5,1,browser-login:');
    Route::post('/logout', [Browser::class, 'logout']);
    Route::get('/me', [Browser::class, 'me'])->middleware(['auth:sanctum', BrowserSession::class]);
});
