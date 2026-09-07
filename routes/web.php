<?php

use App\Http\Controllers\ConnectionController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\WorkspaceController as W;
use App\Http\Middleware\LocalOrOwner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/register', fn () => view('account', ['mode' => 'register']));
Route::post('/register', [RegistrationController::class, 'store'])->middleware('throttle:signup');
Route::get('/verify-email/{id}/{hash}', [RegistrationController::class, 'verify'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
Route::get('/forgot-password', fn () => view('account', ['mode' => 'forgot']));
Route::post('/forgot-password', [RegistrationController::class, 'forgot'])->middleware('throttle:recovery');
Route::get('/reset-password/{token}', fn (string $token) => view('account', ['mode' => 'reset', 'token' => $token]))->name('password.reset');
Route::post('/reset-password', [RegistrationController::class, 'reset'])->middleware('throttle:password-reset');

Route::get('/login', fn () => view('login'))->name('login');
Route::post('/login', function (Request $r) {
    $credentials = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
    if (! Auth::attempt($credentials)) {
        return back()->withErrors(['email' => 'The sign-in details did not match.']);
    }
    if (! Auth::user()->hasVerifiedEmail()) {
        Auth::logout();

        return back()->withErrors(['email' => 'Verify your email before signing in. Use the desktop’s resend verification option if needed.']);
    }
    $r->session()->regenerate();

    return redirect()->intended('/');
})->middleware('throttle:signin');
Route::post('/logout', function (Request $r) {
    Auth::logout();
    $r->session()->invalidate();
    $r->session()->regenerateToken();

    return redirect('/login');
});
Route::middleware(LocalOrOwner::class)->group(function () {
    Route::get('/', fn () => view('app'));
    Route::get('/local/csrf', fn () => response()->json(['token' => csrf_token()]));
    Route::get('/local/state', [W::class, 'state']);
    Route::post('/local/drafts', [W::class, 'save']);
    Route::post('/local/media', [W::class, 'upload']);
    Route::get('/local/media/{media}', [W::class, 'media']);
    foreach (['schedule', 'cancel', 'recover', 'account', 'disconnect', 'analytics'] as $action) {
        Route::post('/local/'.$action, [W::class, $action]);
    }
    Route::get('/connect/{provider}', [ConnectionController::class, 'start'])->whereIn('provider', ['x', 'threads', 'facebook', 'linkedin', 'linkedin_page']);
    Route::get('/oauth/{provider}/callback', [ConnectionController::class, 'callback'])->whereIn('provider', ['x', 'threads', 'facebook', 'linkedin', 'linkedin_page']);
    Route::post('/connections/select', [ConnectionController::class, 'select']);
});
Route::get('/connections/{ticket}', [ConnectionController::class, 'claim'])->middleware('throttle:connect')->where('ticket', '[A-Za-z0-9]{64}');
Route::view('/connected', 'connected');
Route::get('/media/{media}', [W::class, 'publicMedia'])->middleware('signed')->name('media.public');
