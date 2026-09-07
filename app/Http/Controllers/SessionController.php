<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class SessionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email|max:255', 'password' => 'required|string|max:1000', 'workspace_id' => 'nullable|string|size:64']);
        $data['email'] = Str::lower(trim($data['email']));
        abort_unless(Auth::guard('web')->once(collect($data)->only(['email', 'password'])->all()), 401, 'The sign-in details did not match.');
        $user = Auth::guard('web')->user();
        abort_unless($user->hasVerifiedEmail(), 403, 'Verify your email before signing in.');
        $workspace = $user->workspace_id;
        abort_if(! empty($data['workspace_id']) && ! hash_equals($workspace, $data['workspace_id']), 409, 'This Mac belongs to another Sendae workspace. Its drafts have been kept safe.');

        return response()->json([
            'token' => $user->createToken('Sendae desktop', ['mcp:use'])->accessToken,
            'workspace_id' => $workspace,
            'email' => $user->email,
        ])->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->user()->token()->revoke();

        return response()->json(['signed_out' => true])->header('Cache-Control', 'no-store');
    }
}
