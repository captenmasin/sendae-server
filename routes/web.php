<?php

use App\Http\Controllers\AuthorizationController;
use App\Http\Controllers\ConnectionController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Middleware\LocalOrOwner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Controllers\AccessTokenController;

Route::get('/up', fn () => response()->json(['status' => 'ok']));
Route::get('/', fn () => response()->json(['service' => 'Sendae API']));
Route::get('/verify-email/{id}/{hash}', [RegistrationController::class, 'verify'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
Route::get('/reset-password/{token}', function (Request $request, string $token) {
    $data = $request->validate(['email' => 'required|email|max:255']);

    return response('', 302, ['Location' => 'sendae://reset-password?'.http_build_query(['token' => $token, 'email' => $data['email']]), 'Cache-Control' => 'no-store']);
})->name('password.reset');
Route::get('/connections/{ticket}', [ConnectionController::class, 'claim'])->middleware('throttle:connect')->where('ticket', '[A-Za-z0-9]{64}');
Route::get('/oauth/{provider}/callback', [ConnectionController::class, 'callback'])->middleware(LocalOrOwner::class)->whereIn('provider', ['x', 'threads', 'facebook', 'linkedin', 'linkedin_page']);
Route::get('/media/{media}', [WorkspaceController::class, 'publicMedia'])->middleware('signed')->name('media.public');
Route::get('/oauth/authorize', [AuthorizationController::class, 'start'])->middleware('throttle:connect')->name('passport.authorizations.authorize');
Route::post('/oauth/token', [AccessTokenController::class, 'issueToken'])->withoutMiddleware('web')->middleware('throttle:60,1')->name('passport.token');
