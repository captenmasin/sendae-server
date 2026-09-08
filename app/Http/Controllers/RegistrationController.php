<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RegistrationController extends Controller
{
    private function passwordRules(): array
    {
        return ['required', 'string', 'min:8', 'confirmed', function ($attribute, $value, $fail) {
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
        $request->validate(['email' => 'required|string']);
        $request->merge(['email' => Str::lower(trim($request->input('email', '')))]);
        $data = $request->validate(['name' => 'required|string|max:100', 'email' => ['required', 'email', 'max:255', Rule::unique('users')], 'password' => $this->passwordRules()]);
        try {
            User::create($data);
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['email' => 'That email already has an account. Sign in or reset your password.']);
        }
        $message = 'Account created. Sign in to Sendae to continue.';

        return response()->json(['message' => $message], 201);
    }

    public function forgot(Request $request)
    {
        $this->requireMail();
        $data = $request->validate(['email' => 'required|email|max:255']);
        Password::sendResetLink($data);
        $message = 'If that email has a Sendae account, a password reset link is on its way.';

        return response()->json(['message' => $message]);
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

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return response()->json(['message' => 'Password updated. Sign in with your new password.']);
    }
}
