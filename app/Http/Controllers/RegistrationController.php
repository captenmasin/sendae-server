<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RegistrationController extends Controller
{
    private function passwordRules(): array
    {
        return ['required', 'string', 'min:12', 'confirmed', function ($attribute, $value, $fail) {
            if (strlen($value) > 72) {
                $fail('Use a password of at most 72 bytes.');
            }
        }];
    }

    private function requireMail(): void
    {
        abort_if(app()->isProduction() && in_array(config('mail.default'), ['log', 'array', 'failover']), 503, 'Account email delivery is not available. Please try again later.');
    }

    public function store(Request $request)
    {
        $this->requireMail();
        $request->validate(['email' => 'required|string']);
        $request->merge(['email' => Str::lower(trim($request->input('email', '')))]);
        $data = $request->validate(['name' => 'required|string|max:100', 'email' => ['required', 'email', 'max:255', Rule::unique('users')], 'password' => $this->passwordRules()]);
        try {
            $user = User::create($data);
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['email' => 'That email already has an account. Sign in or reset your password.']);
        }
        $user->sendEmailVerificationNotification();
        $message = 'Account created. Check your email to verify it, then sign in to Sendae.';

        return $request->expectsJson() ? response()->json(['message' => $message], 201) : redirect('/login')->with('status', $message);
    }

    public function verify(Request $request, string $id, string $hash)
    {
        $user = User::findOrFail($id);
        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);
        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return redirect('/login')->with('status', 'Email verified. You can now sign in to Sendae on your Mac.');
    }

    public function resend(Request $request)
    {
        $this->requireMail();
        $data = $request->validate(['email' => 'required|email|max:255', 'password' => 'required|string|max:1000']);
        if (Auth::guard('web')->once($data)) {
            $user = Auth::guard('web')->user();
            if (! $user->hasVerifiedEmail()) {
                $user->sendEmailVerificationNotification();
            }
        }

        return response()->json(['message' => 'If those details match an unverified account, a new verification email is on its way.']);
    }

    public function forgot(Request $request)
    {
        $this->requireMail();
        $data = $request->validate(['email' => 'required|email|max:255']);
        Password::sendResetLink($data);
        $message = 'If that email has a Sendae account, a password reset link is on its way.';

        return $request->expectsJson() ? response()->json(['message' => $message]) : back()->with('status', $message);
    }

    public function reset(Request $request)
    {
        $data = $request->validate(['token' => 'required|string', 'email' => 'required|email|max:255', 'password' => $this->passwordRules()]);
        $status = Password::reset($data, function (User $user, string $password) {
            DB::transaction(function () use ($user, $password) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $user->tokens()->select('id'))->update(['revoked' => true]);
                $user->tokens()->update(['revoked' => true]);
                DB::table('sessions')->where('user_id', $user->id)->delete();
            });
            event(new PasswordReset($user));
        });

        return $status === Password::PasswordReset
            ? redirect('/login')->with('status', 'Password updated. Sign in with your new password.')
            : back()->withErrors(['email' => __($status)]);
    }
}
