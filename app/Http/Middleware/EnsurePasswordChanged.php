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
            abort(403, 'You must set a new password before continuing.');
        }

        return $next($request);
    }
}
