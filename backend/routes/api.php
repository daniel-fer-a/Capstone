<?php

use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\ApplicationDocumentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/applications', [ApplicationController::class, 'store'])->middleware('throttle:admissions');
    Route::middleware('auth:sanctum')->prefix('admin')->group(function (): void {
        Route::get('/applications/{application}', [ApplicationController::class, 'show']);
        Route::get('/applications/{application}/documents/{document}', [ApplicationDocumentController::class, 'download']);
    });
});
