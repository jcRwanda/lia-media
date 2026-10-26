<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\Asset;
use App\Models\Tag;
use App\Services\AssetPresenter;
use App\Services\AuditLogger;
use App\Services\DownloadService;
use App\Storage\ObjectStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssetController extends Controller
{
    public function __construct(private AssetPresenter $present, private AuditLogger $audit) {}

    public static function filtered(Request $request): Builder
    {
        $q = Asset::query();
        if ($term = trim((string) $request->query('q'))) {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
            $q->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('description', 'like', $like)->orWhere('original_filename', 'like', $like)->orWhere('credit', 'like', $like)
                ->orWhereHas('tags', fn ($t) => $t->where('name', 'like', $like)));
        }
        foreach (['kind', 'review_state', 'consent_status'] as $f) {
            if ($v = $request->query($f)) {
                $q->whereIn($f, explode(',', $v));
            }
        }
        foreach (['project_id', 'event_id', 'cohort_id', 'category_id', 'uploaded_by'] as $f) {
            if ($v = $request->query($f)) {
                $q->where($f, (int) $v);
            }
        }
        if ($tag = $request->query('tag')) {
            $q->whereHas('tags', fn ($t) => $t->where('slug', Str::slug($tag)));
        }
        if ($album = $request->query('album')) {
            $q->whereHas('albums', fn ($t) => $t->where('albums.uuid', $album));
        }
        if ($from = $request->query('date_from')) {
            $q->where('created_at', '>=', $from);
        }
        if ($to = $request->query('date_to')) {
            $q->where('created_at', '<=', $to.' 23:59:59');
        }
        match ($request->query('sort', 'newest')) {
            'oldest' => $q->orderBy('id'),
            'title' => $q->orderBy('title'),
            'largest' => $q->orderByDesc('size_bytes'),
            'captured' => $q->orderByDesc('captured_at')->orderByDesc('id'),
            default => $q->orderByDesc('id'),
        };

        return $q;
    }

    public function index(Request $request): JsonResponse
    {
        $page = self::filtered($request)->with('derivatives')->paginate(min(96, (int) $request->query('per_page', 48)));

        return response()->json($page->through(fn (Asset $a) => $this->present->card($a)));
    }

    public function show(Asset $asset): JsonResponse
    {
        return response()->json(['asset' => $this->present->detail($asset)]);
    }

    public function update(Request $request, Asset $asset): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:5000'],
            'credit' => ['nullable', 'string', 'max:190'],
            'captured_at' => ['nullable', 'date'],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')],
            'event_id' => ['nullable', 'integer', Rule::exists('events', 'id')],
            'cohort_id' => ['nullable', 'integer', Rule::exists('cohorts', 'id')],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'tags' => ['sometimes', 'array', 'max:30'],
            'tags.*' => ['string', 'max:60'],
        ]);
        $tags = $data['tags'] ?? null;
        unset($data['tags']);
        $before = $asset->only(array_keys($data));
        $asset->update($data);
        if ($tags !== null) {
            $asset->tags()->sync($this->tagIds($tags));
        }
        $this->audit->log('asset.updated', $asset, ['before' => $before, 'after' => $asset->only(array_keys($data)), 'tags' => $tags]);

        return response()->json(['asset' => $this->present->detail($asset->fresh())]);
    }

    public function destroy(Asset $asset): JsonResponse
    {
        $asset->delete(); // soft delete: the original stays in storage until an admin purges it
        $this->audit->log('asset.deleted', $asset);

        return response()->json(['ok' => true]);
    }

    public function download(Request $request, Asset $asset, ObjectStorage $storage): JsonResponse
    {
        $url = $storage->presignGet($asset->storage_key, config('media.ttl.download'), $asset->original_filename, false);
        $this->audit->log('asset.downloaded', $asset, ['variant' => 'original']);

        return response()->json(['url' => $url, 'expires_in' => config('media.ttl.download')]);
    }

    /** Selected originals streamed back as one zip (desktop downloads from the library/gallery). */
    public function downloadZip(Request $request, DownloadService $downloads): StreamedResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:'.DownloadService::MAX_ZIP_ITEMS],
            'items.*' => ['string', 'max:64'],
        ]);
        $wanted = collect($data['items'])->unique()->values();
        $byUuid = Asset::query()->with('derivatives')->whereIn('uuid', $wanted)->get()->keyBy('uuid');
        // Keep the order the browser sent, so the archive mirrors the selection.
        $assets = $wanted->map(fn (string $uuid) => $byUuid->get($uuid))->filter()->values();
        $files = $downloads->downloadable($assets);
        abort_if($files->isEmpty(), 404, 'None of the selected items have a downloadable copy.');

        return $downloads->zip($files, 'library-'.now()->format('Ymd-His').'.zip');
    }

    /** One original streamed over our own origin — how phones walk through a selection. */
    public function file(Asset $asset, DownloadService $downloads): StreamedResponse
    {
        return $downloads->file($asset);
    }

    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'uuids' => ['required', 'array', 'min:1', 'max:500'],
            'uuids.*' => ['string'],
            'add_tags' => ['nullable', 'array'],
            'add_tags.*' => ['string', 'max:60'],
            'album_uuid' => ['nullable', 'string', Rule::exists('albums', 'uuid')->whereNull('deleted_at')],
            'event_id' => ['nullable', 'integer', Rule::exists('events', 'id')],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')],
        ]);
        $assets = Asset::query()->whereIn('uuid', $data['uuids'])->get();
        $tagIds = $this->tagIds($data['add_tags'] ?? []);
        $album = ! empty($data['album_uuid']) ? Album::query()->where('uuid', $data['album_uuid'])->first() : null;
        if ($album) {
            abort_unless($request->user()->canDo('albums.manage'), 403);
        }
        $pos = $album ? (int) $album->assets()->max('album_asset.position') : 0;
        foreach ($assets as $a) {
            if ($tagIds) {
                $a->tags()->syncWithoutDetaching($tagIds);
            }
            if ($album && ! $album->assets()->whereKey($a->id)->exists()) {
                $album->assets()->attach($a->id, ['position' => ++$pos, 'added_by' => $request->user()->id, 'added_at' => now()]);
            }
            $set = array_filter(['event_id' => $data['event_id'] ?? null, 'project_id' => $data['project_id'] ?? null]);
            if ($set) {
                $a->update($set);
            }
        }
        $this->audit->log('asset.bulk_updated', null, ['count' => $assets->count(), 'album' => $album?->uuid, 'tags' => $data['add_tags'] ?? []]);

        return response()->json(['updated' => $assets->count()]);
    }

    /** @return list<int> */
    private function tagIds(array $names): array
    {
        return collect($names)->map(fn ($n) => trim($n))->filter()->unique(fn ($n) => Str::slug($n))
            ->map(fn ($n) => Tag::query()->firstOrCreate(['slug' => Str::slug($n)], ['name' => $n])->id)->values()->all();
    }
}
