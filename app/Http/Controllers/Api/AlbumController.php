<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\Asset;
use App\Services\AssetPresenter;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AlbumController extends Controller
{
    public function __construct(private AssetPresenter $present, private AuditLogger $audit) {}

    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:5000'],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')],
            'event_id' => ['nullable', 'integer', Rule::exists('events', 'id')],
            'cohort_id' => ['nullable', 'integer', Rule::exists('cohorts', 'id')],
        ];
    }

    private function summary(Album $al): array
    {
        $cover = $al->cover ?? $al->assets->first();

        return [
            'uuid' => $al->uuid, 'id' => $al->id, 'title' => $al->title, 'description' => $al->description,
            'project_id' => $al->project_id, 'event_id' => $al->event_id, 'cohort_id' => $al->cohort_id,
            'event' => $al->event?->title, 'project' => $al->project?->name,
            'asset_count' => (int) ($al->assets_count ?? $al->assets()->count()),
            'cover_url' => $cover ? $this->present->derivUrl($cover->loadMissing('derivatives'), 'thumb') : null,
            'cover_uuid' => $al->cover?->uuid, 'created_by' => $al->creator?->name, 'updated_at' => $al->updated_at?->toIso8601String(),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $albums = Album::query()->withCount('assets')->with(['cover.derivatives', 'event:id,title', 'project:id,name', 'creator:id,name', 'assets' => fn ($q) => $q->with('derivatives')->limit(1)])
            ->when($request->query('q'), fn ($q, $t) => $q->where('title', 'like', "%{$t}%"))->latest('updated_at')->get();

        return response()->json(['data' => $albums->map(fn ($a) => $this->summary($a))]);
    }

    public function store(Request $request): JsonResponse
    {
        $album = Album::query()->create($request->validate($this->rules()) + ['created_by' => $request->user()->id]);
        $this->audit->log('album.created', $album);

        return response()->json(['album' => $this->summary($album)], 201);
    }

    public function show(Album $album): JsonResponse
    {
        $assets = $album->assets()->with('derivatives')->get();

        return response()->json(['album' => $this->summary($album->load(['cover.derivatives', 'event', 'project', 'creator'])), 'assets' => $assets->map(fn ($a) => $this->present->card($a) + ['position' => $a->pivot->position])]);
    }

    public function update(Request $request, Album $album): JsonResponse
    {
        $data = $request->validate(array_merge($this->rules(), ['title' => ['sometimes', 'required', 'string', 'max:190'], 'cover_uuid' => ['nullable', 'string']]));
        if (array_key_exists('cover_uuid', $data)) {
            $data['cover_asset_id'] = $data['cover_uuid'] ? $album->assets()->where('assets.uuid', $data['cover_uuid'])->value('assets.id') : null;
            unset($data['cover_uuid']);
        }
        $album->update($data);
        $this->audit->log('album.updated', $album);

        return response()->json(['album' => $this->summary($album->fresh())]);
    }

    public function destroy(Album $album): JsonResponse
    {
        $album->delete();
        $this->audit->log('album.deleted', $album);

        return response()->json(['ok' => true]);
    }

    public function addAssets(Request $request, Album $album): JsonResponse
    {
        $data = $request->validate(['uuids' => ['required', 'array', 'min:1', 'max:500'], 'uuids.*' => ['string']]);
        $pos = (int) $album->assets()->max('album_asset.position');
        $added = 0;
        foreach (Asset::query()->whereIn('uuid', $data['uuids'])->get() as $a) {
            if (! $album->assets()->whereKey($a->id)->exists()) {
                $album->assets()->attach($a->id, ['position' => ++$pos, 'added_by' => $request->user()->id, 'added_at' => now()]);
                $added++;
            }
        }
        $album->touch();
        $this->audit->log('album.assets_added', $album, ['count' => $added]);

        return response()->json(['added' => $added]);
    }

    public function removeAsset(Album $album, Asset $asset): JsonResponse
    {
        $album->assets()->detach($asset->id);
        if ($album->cover_asset_id === $asset->id) {
            $album->update(['cover_asset_id' => null]);
        }
        $this->audit->log('album.asset_removed', $album, ['asset' => $asset->uuid]);

        return response()->json(['ok' => true]);
    }

    public function reorder(Request $request, Album $album): JsonResponse
    {
        $data = $request->validate(['uuids' => ['required', 'array'], 'uuids.*' => ['string']]);
        $ids = Asset::query()->whereIn('uuid', $data['uuids'])->pluck('id', 'uuid');
        DB::transaction(function () use ($album, $data, $ids) {
            foreach (array_values($data['uuids']) as $i => $uuid) {
                if (isset($ids[$uuid])) {
                    DB::table('album_asset')->where('album_id', $album->id)->where('asset_id', $ids[$uuid])->update(['position' => $i + 1]);
                }
            }
        });

        return response()->json(['ok' => true]);
    }
}
