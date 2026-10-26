<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

/** Strict server-to-server API key authentication for the platform API. */
class MaterialRequestClientAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-LIA-Key');
        if (blank($key)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $client = ApiClient::query()
            ->where('active', true)
            ->get()
            ->first(fn (ApiClient $candidate) => Hash::check($key, $candidate->key_hash));

        if (! $client) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $origin = $request->headers->get('Origin');
        if ($client->allowed_origin && $origin && $origin !== $client->allowed_origin) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->attributes->set('api_client', $client);

        return $next($request);
    }
}
