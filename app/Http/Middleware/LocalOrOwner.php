<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class LocalOrOwner
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()) {
            return $request->expectsJson() ? response()->json(['message' => 'Sign in to continue.'], 401) : redirect('/login');
        }

        abort_unless($request->user()->hasVerifiedEmail(), 403, 'Verify your email before opening your workspace.');

        return $next($request);
    }
}
