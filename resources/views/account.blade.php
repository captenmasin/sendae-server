<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>Account · Sendae</title>@vite('resources/css/app.css')</head><body><main class="login"><div class="brand">sendae<span>✳</span></div><h1>{{ $mode === 'register' ? 'Create your account.' : ($mode === 'reset' ? 'Choose a new password.' : 'Reset your password.') }}</h1>
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
<form method="post" action="{{ $mode === 'register' ? '/register' : ($mode === 'reset' ? '/reset-password' : '/forgot-password') }}">@csrf
@if($mode === 'register')<label>Name<input name="name" autocomplete="name" maxlength="100" value="{{ old('name') }}" required></label>@endif
@if($mode === 'reset')<input type="hidden" name="token" value="{{ $token }}">@endif
<label>Email<input name="email" type="email" autocomplete="username" value="{{ old('email', request('email')) }}" required></label>
@if($mode !== 'forgot')<label>Password · at least 12 characters<input name="password" type="password" autocomplete="new-password" minlength="12" required></label><label>Confirm password<input name="password_confirmation" type="password" autocomplete="new-password" required></label>@endif
<button class="primary">{{ $mode === 'register' ? 'Create account' : ($mode === 'reset' ? 'Update password' : 'Send reset link') }}</button></form><p><a href="/login">Back to sign in</a></p></main></body></html>
