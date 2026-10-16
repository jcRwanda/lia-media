<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShareLink;
use App\Services\ShareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/** Visitor side of share links. No staff session; throttled; responses are never cached. */
class PublicShareController extends Controller
{
    public function __construct(private ShareService $shares) {}

    private function cookie(string $token, string $raw): Cookie
    {
        return cookie(ShareService::COOKIE, $raw, ShareService::GRANT_MINUTES, "/api/s/{$token}", null, config('session.secure'), true, false, 'lax');
    }

    private function meta(ShareLink $link): array
    {
        return ['title' => $link->title, 'message' => $link->message, 'mode' => $link->mode, 'allow_download' => $link->allow_download, 'expires_at' => $link->expires_at?->toIso8601String()];
    }

    private function nocache(JsonResponse $r): JsonResponse
    {
        return $r->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }

    public function show(Request $request, string $token): JsonResponse
    {
        [$link, $recipient] = $this->shares->resolve($token);
        $grant = $this->shares->currentGrant($link, $request);
        $cookie = null;
        if (! $grant && $link->mode === 'access_code') {
            return $this->nocache(response()->json(['share' => $this->meta($link), 'requires_code' => true]));
        }
        if (! $grant) {
            [$grant, $raw] = $this->shares->issueGrant($link, $request, $recipient);
            $cookie = $this->cookie($token, $raw);
        }
        $grant->forceFill(['last_used_at' => now()])->save();
        $items = $this->shares->visibleAssets($link)->map(fn ($a) => $this->shares->visitorItem($link, $a))->values();
        $res = response()->json(['share' => $this->meta($link), 'requires_code' => false, 'items' => $items, 'grant_expires_at' => $grant->expires_at->toIso8601String(), 'url_ttl_seconds' => config('media.ttl.preview')]);

        return $this->nocache($cookie ? $res->withCookie($cookie) : $res);
    }

    public function unlock(Request $request, string $token): JsonResponse
    {
        [$link] = $this->shares->resolve($token);
        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);
        [$grant, $raw] = $this->shares->unlock($link, $request, $data['code']);

        return $this->nocache(response()->json(['ok' => true, 'grant_expires_at' => $grant->expires_at->toIso8601String()])->withCookie($this->cookie($token, $raw)));
    }

    public function download(Request $request, string $token, string $asset): JsonResponse
    {
        [$link] = $this->shares->resolve($token);
        abort_unless($this->shares->currentGrant($link, $request), 401, 'Open the link again to continue.');
        $a = $this->shares->visibleAssets($link)->firstWhere('uuid', $asset);
        abort_unless($a, 404);

        return $this->nocache(response()->json(['url' => $this->shares->downloadUrl($link, $a, $request)]));
    }
}
