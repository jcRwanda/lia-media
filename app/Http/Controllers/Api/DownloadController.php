<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Album;
use App\Models\Asset;
use App\Services\AuditLogger;
use App\Storage\ObjectStorage;
use App\Support\StreamingZip;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Originals for staff: one album, or the items they selected, streamed as a zip. */
class DownloadController extends Controller
{
    public const MAX_ITEMS = 500;

    public function __construct(private ObjectStorage $storage, private AuditLogger $audit) {}

    public function __invoke(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'items' => ['required_without:album', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*' => ['string', 'max:64'],
            'album' => ['required_without:items', 'string', Rule::exists('albums', 'uuid')->whereNull('deleted_at')],
        ]);

        [$assets, $name] = $this->selection($data);
        abort_if($assets->isEmpty(), 404, 'Nothing to download.');
        abort_if($assets->count() > self::MAX_ITEMS, 422, 'Select at most '.self::MAX_ITEMS.' items at a time.');

        $zipName = (Str::slug($name) ?: 'lia-media').'-'.now()->format('Ymd-His').'.zip';

        return response()->stream(function () use ($assets) {
            $zip = new StreamingZip(fopen('php://output', 'wb'));
            foreach ($assets as $asset) {
                $zip->add($asset->original_filename, $this->storage->readChunks($asset->storage_key));
                $this->audit->log('asset.downloaded', $asset, ['variant' => 'original', 'zip' => true]);
            }
            $zip->finish();
        }, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$zipName.'"',
            'Cache-Control' => 'no-store, private',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Selected items keep the order the browser sent. An album download follows the album order.
     *
     * @param  array{items?: list<string>, album?: string}  $data
     * @return array{0: Collection<int, Asset>, 1: string}
     */
    private function selection(array $data): array
    {
        if (! empty($data['items'])) {
            $wanted = collect($data['items'])->unique()->values();
            $byUuid = Asset::query()->whereIn('uuid', $wanted)->get()->keyBy('uuid');

            return [$wanted->map(fn (string $uuid) => $byUuid->get($uuid))->filter()->values(), 'lia-media'];
        }

        $album = Album::query()->where('uuid', $data['album'])->firstOrFail();

        return [$album->assets()->get(), $album->title];
    }
}
