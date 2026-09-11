<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->merge(['email' => strtolower(trim((string) $request->input('email', '')))]);
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:8', 'max:128', 'confirmed', function ($attribute, $value, $fail) {
                if (strlen((string) $value) > 72) {
                    $fail('Use a password of at most 72 bytes.');
                }
            }],
            'current_password' => [Rule::requiredIf(fn (): bool => $request->filled('password') || $request->input('email') !== $user->email), 'current_password'],
        ]);
        $user->fill(['name' => $data['name'], 'email' => $data['email']]);
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();

        return response()->json(['name' => $user->name, 'email' => $user->email]);
    }
}
