<?php

use App\Http\Controllers\AuthorizationController;
use App\Http\Controllers\ConnectionController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\WorkspaceController as W;
use App\Http\Controllers\WorkspacesController;
use App\Http\Middleware\LocalOrOwner;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Middleware\CheckToken;

Route::post('/register', [RegistrationController::class, 'store'])->middleware('throttle:signup');
Route::post('/forgot-password', [RegistrationController::class, 'forgot'])->middleware('throttle:recovery');

Route::post('/reset-password', [RegistrationController::class, 'reset'])->middleware('throttle:password-reset');

Route::post('/session', [SessionController::class, 'store'])->middleware('throttle:signin');

Route::middleware(['auth:api', LocalOrOwner::class, CheckToken::using('mcp:use'), 'throttle:120,1'])->group(function () {
    Route::get('/authorizations/{ticket}', [AuthorizationController::class, 'show'])->where('ticket', '[A-Za-z0-9]{64}');
    Route::post('/authorizations/{ticket}', [AuthorizationController::class, 'decide'])->where('ticket', '[A-Za-z0-9]{64}');
    Route::get('/connections/{ticket}', [ConnectionController::class, 'choices'])->where('ticket', '[A-Za-z0-9]{64}');
    Route::post('/connections/{ticket}', [ConnectionController::class, 'select'])->where('ticket', '[A-Za-z0-9]{64}');
    Route::get('/workspaces', [WorkspacesController::class, 'index']);
    Route::post('/workspaces', [WorkspacesController::class, 'store']);
    Route::patch('/workspaces/{workspace}', [WorkspacesController::class, 'update']);
    Route::get('/workspaces/{workspace}/image', [WorkspacesController::class, 'image']);
    Route::post('/workspaces/{workspace}/image', [WorkspacesController::class, 'uploadImage']);
    Route::delete('/workspaces/{workspace}/image', [WorkspacesController::class, 'destroyImage']);
    Route::patch('/profile', [ProfileController::class, 'update']);
    Route::delete('/session', [SessionController::class, 'destroy']);
    Route::get('/state', [W::class, 'state']);
    Route::post('/drafts', [W::class, 'save']);
    Route::post('/media', [W::class, 'upload']);
    Route::get('/media/{media}', [W::class, 'media']);
    foreach (['deleteDraft', 'schedule', 'cancel', 'deletePublication', 'recover', 'account', 'disconnect', 'analytics'] as $action) {
        Route::post('/'.$action, [W::class, $action]);
    }
    Route::post('/connectBluesky', [ConnectionController::class, 'bluesky'])->middleware('throttle:connect');
    Route::post('/connect', [ConnectionController::class, 'ticket'])->middleware('throttle:connect');
});
