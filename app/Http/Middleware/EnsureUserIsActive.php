<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** A disabled account loses access on its next request, even with a live session. */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user() && ! $request->user()->isActive()) {
            Auth::guard('web')->logout();
            abort(403, 'This account is disabled.');
        }

        return $next($request);
    }
}
