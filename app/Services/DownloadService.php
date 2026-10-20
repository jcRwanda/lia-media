<?php

namespace App\Services;

use App\Models\Asset;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Staff downloads: the original bytes of any asset the user is allowed to download, pulled from
 * object storage and streamed over our own origin — one zip for a selection or a whole album, one
 * file at a time on phones. Every downloaded asset is audited like the single-asset download.
 */
class DownloadService
{
    /** Mirrors the visitor zip cap: keeps one request inside classic zip limits and server time. */
    public const MAX_ZIP_ITEMS = 500;

    public function __construct(private StreamedMedia $media, private AuditLogger $audit) {}

    /** Only assets whose original is actually in storage have bytes to send. */
    public function downloadable(Collection $assets): Collection
    {
        return $assets->filter(fn (Asset $a) => $a->storage_status === 'stored')->values();
    }

    /**
     * Selected originals streamed back as one zip; each asset is audited as a download.
     *
     * @param  array<string, mixed>  $context  extra audit properties (album uuid, selection source…)
     */
    public function zip(Collection $assets, string $zipName, array $context = []): StreamedResponse
    {
        $files = $this->downloadable($assets)
            ->map(fn (Asset $a) => ['asset' => $a, 'storage_key' => $a->storage_key, 'filename' => $a->original_filename])
            ->all();

        return $this->media->zip($zipName, $files, function (array $file) use ($context) {
            $this->audit->log('asset.downloaded', $file['asset'], array_merge(['variant' => 'original', 'zip' => true], $context));
        });
    }

    /**
     * One original streamed over our own origin — how phones walk through a selection.
     *
     * @param  array<string, mixed>  $context  extra audit properties
     */
    public function file(Asset $asset, array $context = []): StreamedResponse
    {
        abort_unless($asset->storage_status === 'stored', 404, 'This file is not available for download yet.');
        $this->audit->log('asset.downloaded', $asset, array_merge(['variant' => 'original', 'stream' => true], $context));

        return $this->media->file(
            ['storage_key' => $asset->storage_key, 'filename' => $asset->original_filename],
            ['Content-Type' => $asset->mime_type ?: 'application/octet-stream'],
        );
    }
}
