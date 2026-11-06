<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SessionController;

Route::get('/', fn () => response()->json([
    'service' => 'CCHIA Postulaciones',
    'stage' => 'Integración local de postulaciones, seguimiento y acceso.',
]));

Route::prefix('api/v1/session')->group(function (): void {
    Route::post('/login', [SessionController::class, 'login'])->middleware('throttle:login');
    Route::post('/forgot-password', [SessionController::class, 'forgotPassword'])->middleware('throttle:passwords');
    Route::post('/reset-password', [SessionController::class, 'resetPassword'])->middleware('throttle:passwords');
    Route::middleware(['auth', 'auth.session'])->group(function (): void {
        Route::get('/me', [SessionController::class, 'current']);
        Route::post('/logout', [SessionController::class, 'logout']);
    });
});
