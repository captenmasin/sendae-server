<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class LocalOrOwner
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()) {
            return response()->json(['message' => 'Sign in to continue.'], 401);
        }

        return $next($request);
    }
}
