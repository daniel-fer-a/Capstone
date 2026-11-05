<?php

use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\ApplicationDocumentController;
use App\Http\Controllers\ApplicationTrackingController;
use App\Http\Controllers\AdmissionCapabilitiesController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use App\Http\Controllers\IntakeController;
use App\Http\Controllers\SponsorInvitationController;
use App\Http\Controllers\AdmissionCaseworkController;
use App\Http\Controllers\MembershipRegisterController;
use App\Http\Controllers\NotificationAdministrationController;

Route::prefix('v1')->group(function (): void {
    Route::withoutMiddleware(EnsureFrontendRequestsAreStateful::class)->group(function (): void {
        Route::get('/admissions/capabilities', AdmissionCapabilitiesController::class);
        Route::post('/applications', [ApplicationController::class, 'store'])->middleware('throttle:admissions');
        Route::post('/applications/track', ApplicationTrackingController::class)->middleware('throttle:tracking');
        Route::post('/applications/appeal', [AdmissionCaseworkController::class, 'appeal'])->middleware('throttle:admissions');
        Route::post('/applications/corrections/submit', [AdmissionCaseworkController::class, 'submitCorrection'])->middleware('throttle:admissions');
        Route::post('/intakes', [IntakeController::class, 'store'])->middleware('throttle:intakes');
        Route::post('/intakes/resume', [IntakeController::class, 'resume'])->middleware('throttle:tracking');
        Route::post('/intakes/sponsors', [IntakeController::class, 'replace'])->middleware('throttle:intakes');
        Route::post('/intakes/submit', [IntakeController::class, 'submit'])->middleware('throttle:admissions');
    });
    Route::middleware('auth:sanctum')->prefix('sponsor')->group(function (): void {
        Route::get('/invitations', [SponsorInvitationController::class, 'index']);
        Route::post('/invitations/{invitation}/decision', [SponsorInvitationController::class, 'decide'])->whereUuid('invitation')->middleware('throttle:sponsorships');
    });
    Route::middleware('auth:sanctum')->prefix('admin')->group(function (): void {
        Route::get('/members/export', [MembershipRegisterController::class, 'export'])->middleware('throttle:institutional-exports');
        Route::get('/members', [MembershipRegisterController::class, 'index']);
        Route::get('/members/{registration}', [MembershipRegisterController::class, 'show'])->whereUuid('registration');
        Route::get('/notifications', [NotificationAdministrationController::class, 'index']);
        Route::post('/notifications/{notification}/retry', [NotificationAdministrationController::class, 'retry'])->whereUuid('notification')->middleware('throttle:notification-retries');
        Route::get('/applications', [ApplicationController::class, 'index']);
        Route::get('/applications/{application}', [ApplicationController::class, 'show'])->whereUuid('application');
        Route::post('/applications/{application}/start-review', [ApplicationController::class, 'startReview'])->whereUuid('application');
        Route::post('/applications/{application}/decision', [ApplicationController::class, 'recordDecision'])->whereUuid('application')->middleware('throttle:sponsorships');
        Route::post('/applications/{application}/appeal-resolution', [AdmissionCaseworkController::class, 'assembly'])->whereUuid('application')->middleware('throttle:sponsorships');
        Route::post('/applications/{application}/corrections', [AdmissionCaseworkController::class, 'requestCorrection'])->whereUuid('application')->middleware('throttle:sponsorships');
        Route::post('/applications/{application}/corrections/{correction}/review', [AdmissionCaseworkController::class, 'reviewCorrection'])->whereUuid(['application', 'correction'])->middleware('throttle:sponsorships');
        Route::post('/applications/{application}/formalize', [AdmissionCaseworkController::class, 'formalize'])->whereUuid('application')->middleware('throttle:sponsorships');
        Route::get('/applications/{application}/revisions/{revision}', [AdmissionCaseworkController::class, 'downloadRevision'])->whereUuid(['application', 'revision']);
        Route::get('/applications/{application}/documents/{document}', [ApplicationDocumentController::class, 'download'])->whereUuid(['application', 'document']);
    });
});
