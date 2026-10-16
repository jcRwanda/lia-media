<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\PublicationUsage;
use App\Services\AssetPresenter;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PublicationController extends Controller
{
    public const CHANNELS = ['website', 'instagram', 'facebook', 'x', 'youtube', 'tiktok', 'press', 'print', 'report', 'other'];

    public function __construct(private AuditLogger $audit, private AssetPresenter $present) {}

    public function index(Request $request): JsonResponse
    {
        $rows = PublicationUsage::query()->with(['asset' => fn ($q) => $q->withTrashed()->with('derivatives'), 'recorder:id,name'])
            ->when($request->query('channel'), fn ($q, $c) => $q->where('channel', $c))->latest('published_on')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn ($p) => ['id' => $p->id, 'channel' => $p->channel, 'url' => $p->url, 'title' => $p->title, 'published_on' => $p->published_on?->toDateString(), 'recorded_by' => $p->recorder?->name, 'asset' => $p->asset ? $this->present->card($p->asset) : null])]);
    }

    public function store(Request $request, Asset $asset): JsonResponse
    {
        abort_unless($asset->review_state === 'published', 422, 'Publish the item before recording where it was used.');
        $d = $request->validate(['channel' => ['required', Rule::in(self::CHANNELS)], 'url' => ['nullable', 'url', 'max:500'], 'title' => ['nullable', 'string', 'max:190'], 'published_on' => ['required', 'date'], 'notes' => ['nullable', 'string', 'max:2000']]);
        $p = $asset->publications()->create($d + ['recorded_by' => $request->user()->id]);
        $this->audit->log('publication.recorded', $asset, ['channel' => $p->channel, 'url' => $p->url]);

        return response()->json(['asset' => $this->present->detail($asset->fresh())], 201);
    }

    public function destroy(PublicationUsage $publication): JsonResponse
    {
        $asset = $publication->asset;
        $publication->delete();
        $this->audit->log('publication.deleted', $asset, ['channel' => $publication->channel]);

        return response()->json(['ok' => true]);
    }
}
