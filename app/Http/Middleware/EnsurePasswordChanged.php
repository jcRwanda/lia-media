<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Blocks every authenticated API route until the temporary password has been replaced. */
class EnsurePasswordChanged
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password) {
            // `code` lets the SPA recognise the lock from any failing request, not just /auth/me.
            return response()->json([
                'message' => 'You must set a new password before continuing.',
                'code' => 'must_change_password',
            ], 403);
        }

        return $next($request);
    }
}
