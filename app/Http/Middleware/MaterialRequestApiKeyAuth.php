<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class MaterialRequestApiKeyAuth
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-LIA-Key') ?? $request->header('X-Api-Key');
        $allowed = config('material-requests.allowed_origins', []);
        $origin = $request->headers->get('Origin');

        if ($key) {
            $client = ApiClient::query()->where('active', true)->get()->first(function ($client) use ($key) {
                return Hash::check($key, $client->key_hash);
            });

            if (! $client) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            if (! empty($allowed) && $origin && ! in_array($origin, $allowed, true)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            if (! empty($allowed) && $origin) {
                $client->allowed_origin = $origin;
            }

            $request->attributes->set('api_client', $client);

            return $next($request);
        }

        if ($origin && ! empty($allowed) && in_array($origin, $allowed, true)) {
            return $next($request);
        }

        if (empty($allowed)) {
            return $next($request);
        }

        // The hosted form calls its own origin; browsers do not send Origin on same-origin GETs.
        $refererHost = parse_url((string) $request->headers->get('referer'), PHP_URL_HOST);
        if ($refererHost && $refererHost === $request->getHost()) {
            return $next($request);
        }
        if ($request->headers->get('sec-fetch-site') === 'same-origin') {
            return $next($request);
        }

        return response()->json(['message' => 'Unauthorized'], 401);
    }
}
