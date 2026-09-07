<?php

use App\Http\Controllers\ConnectionController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\WorkspaceController as W;
use App\Http\Controllers\WorkspacesController;
use App\Http\Middleware\LocalOrOwner;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Middleware\CheckToken;

Route::post('/register', [RegistrationController::class, 'store'])->middleware('throttle:signup');
Route::post('/verification', [RegistrationController::class, 'resend'])->middleware('throttle:verification');
Route::post('/forgot-password', [RegistrationController::class, 'forgot'])->middleware('throttle:recovery');

Route::post('/session', [SessionController::class, 'store'])->middleware('throttle:signin');

Route::middleware(['auth:api', LocalOrOwner::class, CheckToken::using('mcp:use'), 'throttle:120,1'])->group(function () {
    Route::get('/workspaces', [WorkspacesController::class, 'index']);
    Route::post('/workspaces', [WorkspacesController::class, 'store']);
    Route::patch('/workspaces/{workspace}', [WorkspacesController::class, 'update']);
    Route::delete('/session', [SessionController::class, 'destroy']);
    Route::get('/state', [W::class, 'state']);
    Route::post('/drafts', [W::class, 'save']);
    Route::post('/media', [W::class, 'upload']);
    Route::get('/media/{media}', [W::class, 'media']);
    foreach (['deleteDraft', 'schedule', 'cancel', 'recover', 'account', 'disconnect', 'analytics'] as $action) {
        Route::post('/'.$action, [W::class, $action]);
    }
    Route::post('/connect', [ConnectionController::class, 'ticket'])->middleware('throttle:connect');
});
