<?php

use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\ApplicationDocumentController;
use App\Http\Controllers\ApplicationTrackingController;
use App\Http\Controllers\AdmissionCapabilitiesController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

Route::prefix('v1')->group(function (): void {
    Route::withoutMiddleware(EnsureFrontendRequestsAreStateful::class)->group(function (): void {
        Route::get('/admissions/capabilities', AdmissionCapabilitiesController::class);
        Route::post('/applications', [ApplicationController::class, 'store'])->middleware('throttle:admissions');
        Route::post('/applications/track', ApplicationTrackingController::class)->middleware('throttle:tracking');
    });
    Route::middleware('auth:sanctum')->prefix('admin')->group(function (): void {
        Route::get('/applications', [ApplicationController::class, 'index']);
        Route::get('/applications/{application}', [ApplicationController::class, 'show'])->whereUuid('application');
        Route::post('/applications/{application}/start-review', [ApplicationController::class, 'startReview'])->whereUuid('application');
        Route::get('/applications/{application}/documents/{document}', [ApplicationDocumentController::class, 'download'])->whereUuid(['application', 'document']);
    });
});
