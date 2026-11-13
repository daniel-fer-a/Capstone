<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\WorkspaceInvitationController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/workspace/invitations')->middleware('throttle:workspace-invitations')->group(function(): void {
    Route::post('/preview',[WorkspaceInvitationController::class,'preview']);
    Route::post('/accept',[WorkspaceInvitationController::class,'accept'])->middleware('auth.session');
    Route::post('/decline',[WorkspaceInvitationController::class,'decline'])->middleware('auth.session');
});

Route::middleware(['auth','auth.session'])->group(function(): void {
    Route::get('/api/v1/profile',[ProfileController::class,'show']);
    Route::post('/api/v1/profile',[ProfileController::class,'update'])->middleware('throttle:workspace-writes');
    Route::post('/api/v1/profile/password',[ProfileController::class,'password'])->middleware('throttle:passwords');
    Route::prefix('api/v1/workspace/commissions')->group(function(): void {
        Route::get('/',[WorkspaceController::class,'index']);
        Route::post('/',[WorkspaceController::class,'create'])->middleware('throttle:workspace-writes');
        Route::whereUuid('commission')->prefix('{commission}')->group(function(): void {
            Route::get('/',[WorkspaceController::class,'show']);
            Route::post('/',[WorkspaceController::class,'update'])->middleware('throttle:workspace-writes');
            Route::get('/projects',[WorkspaceController::class,'projects']);
            Route::post('/projects',[WorkspaceController::class,'createProject'])->middleware('throttle:workspace-writes');
            Route::post('/projects/{project}',[WorkspaceController::class,'updateProject'])->whereUuid('project')->middleware('throttle:workspace-writes');
            Route::get('/projects/{project}/tasks',[WorkspaceController::class,'tasks'])->whereUuid('project');
            Route::post('/projects/{project}/tasks',[WorkspaceController::class,'createTask'])->whereUuid('project')->middleware('throttle:workspace-writes');
            Route::post('/projects/{project}/tasks/{task}',[WorkspaceController::class,'updateTask'])->whereUuid(['project','task'])->middleware('throttle:workspace-writes');
            Route::get('/invitations',[WorkspaceInvitationController::class,'index']);
            Route::post('/invitations',[WorkspaceInvitationController::class,'issue'])->middleware('throttle:workspace-writes');
            Route::post('/invitations/{invitation}/cancel',[WorkspaceInvitationController::class,'cancel'])->whereUuid('invitation')->middleware('throttle:workspace-writes');
            Route::post('/members/{assignment}/revoke',[WorkspaceController::class,'revokeMember'])->whereUuid('assignment')->middleware('throttle:workspace-writes');
            Route::get('/resources',[WorkspaceController::class,'resources']);
            Route::post('/resources',[WorkspaceController::class,'uploadResource'])->middleware('throttle:workspace-writes');
            Route::get('/resources/{resource}',[WorkspaceController::class,'download'])->whereUuid('resource');
            Route::get('/events',[WorkspaceController::class,'events']);
        });
    });
});
