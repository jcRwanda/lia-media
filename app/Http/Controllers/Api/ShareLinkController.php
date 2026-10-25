<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\ShareLink;
use App\Services\AuditLogger;
use App\Services\ShareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ShareLinkController extends Controller
{
    public function __construct(private ShareService $shares) {}

    private function url(string $token): string
    {
        return url("/s/{$token}");
    }

    public function index(Request $request): JsonResponse
    {
        $links = ShareLink::query()->with('creator:id,name')
            ->when($request->query('target_uuid'), function ($q, $uuid) {
                $album = Album::query()->where('uuid', $uuid)->value('id');
                $asset = Asset::query()->where('uuid', $uuid)->value('id');
                $q->where(fn ($w) => $w->where(fn ($x) => $x->where('shareable_type', 'album')->where('shareable_id', $album))->orWhere(fn ($x) => $x->where('shareable_type', 'asset')->where('shareable_id', $asset)));
            })->latest('id')->limit(200)->get();

        return response()->json(['data' => $links->map(fn ($l) => $this->shares->staffPayload($l))]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'target_type' => ['required', Rule::in(['album', 'asset'])],
            'target_uuid' => ['required', 'string'],
            'mode' => ['required', Rule::in(ShareLink::MODES)],
            'title' => ['nullable', 'string', 'max:190'],
            'message' => ['nullable', 'string', 'max:2000'],
            'access_code' => ['nullable', 'string', 'max:40'],
            'allow_download' => ['boolean'],
            'include_originals' => ['boolean'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'recipients' => ['nullable', 'array', 'max:100'],
            'recipients.*' => ['email'],
        ]);
        $target = $d['target_type'] === 'album' ? Album::query()->where('uuid', $d['target_uuid'])->firstOrFail() : Asset::query()->where('uuid', $d['target_uuid'])->firstOrFail();
        $res = $this->shares->create($request->user(), $target, $d);

        return response()->json([
            'share' => $this->shares->staffPayload($res['link']),
            // Shown ONCE: only hashes are stored.
            'url' => $res['link']->mode === 'restricted' ? null : $this->url($res['token']),
            'access_code' => $res['code'],
            'recipients' => array_map(fn ($r) => ['email' => $r['email'], 'url' => $this->url($r['token'])], $res['recipients']),
        ], 201);
    }

    public function show(ShareLink $share): JsonResponse
    {
        $attempts = DB::table('share_access_attempts')->where('share_link_id', $share->id)->selectRaw('SUM(CASE WHEN succeeded THEN 1 ELSE 0 END) as ok, SUM(CASE WHEN succeeded THEN 0 ELSE 1 END) as failed')->first();
        $log = AuditLog::query()->where('subject_type', 'ShareLink')->where('subject_id', $share->id)->latest('id')->limit(50)->get();

        return response()->json([
            'share' => $this->shares->staffPayload($share),
            'recipients' => $share->recipients()->get(['id', 'email', 'name', 'revoked_at', 'last_accessed_at']),
            'grants' => ['active' => $share->grants()->whereNull('revoked_at')->where('expires_at', '>', now())->count(), 'total' => $share->grants()->count()],
            'attempts' => ['succeeded' => (int) $attempts->ok, 'failed' => (int) $attempts->failed],
            'log' => $log->map(fn ($l) => ['action' => $l->action, 'ip' => $l->ip, 'properties' => $l->properties, 'at' => $l->created_at?->toIso8601String()]),
            'url_ttl_seconds' => config('media.ttl.preview'),
        ]);
    }

    public function revoke(Request $request, ShareLink $share): JsonResponse
    {
        $this->shares->revoke($share, $request->user());

        return response()->json(['share' => $this->shares->staffPayload($share->fresh())]);
    }

    public function rotate(Request $request, ShareLink $share): JsonResponse
    {
        abort_if($share->mode === 'restricted', 422, 'Restricted links use personal recipient links; revoke and re-add a recipient instead.');
        abort_if($share->revoked_at, 422, 'Revoked links cannot be rotated.');

        return response()->json(['url' => $this->url($this->shares->rotate($share, $request->user()))]);
    }

    public function addRecipient(Request $request, ShareLink $share): JsonResponse
    {
        abort_unless($share->mode === 'restricted', 422, 'Only restricted links have recipients.');
        $d = $request->validate(['email' => ['required', 'email'], 'name' => ['nullable', 'string', 'max:120']]);
        $r = $this->shares->addRecipient($share, $d['email'], $d['name'] ?? null);

        return response()->json(['email' => $r['email'], 'url' => $this->url($r['token'])], 201);
    }

    public function revokeRecipient(Request $request, ShareLink $share, int $recipient): JsonResponse
    {
        $r = $share->recipients()->findOrFail($recipient);
        $r->update(['revoked_at' => now()]);
        $share->grants()->where('share_recipient_id', $r->id)->update(['revoked_at' => now()]);
        app(AuditLogger::class)->log('share.recipient_revoked', $share, ['email' => $r->email]);

        return response()->json(['ok' => true]);
    }
}
