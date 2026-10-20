<?php

namespace App\Services;

use App\Storage\ObjectStorage;
use App\Support\StreamingZip;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams bytes out of object storage over our own origin: one file, or many files as one zip.
 * Chunks are pulled from storage and written straight to the response, so nothing is ever
 * buffered whole in memory or on disk and no bucket CORS rule is needed for the browser.
 */
class StreamedMedia
{
    public function __construct(private ObjectStorage $storage) {}

    /**
     * One file, attachment-disposition. Shared by the visitor file endpoint and staff downloads.
     *
     * @param  array{storage_key: string, filename: string}  $file
     */
    public function file(array $file, array $headers = []): StreamedResponse
    {
        $safe = str_replace(['"', "\r", "\n"], '', $file['filename']);

        return response()->stream(function () use ($file) {
            foreach ($this->storage->readChunks($file['storage_key']) as $chunk) {
                echo $chunk;
            }
        }, 200, array_merge([
            'Content-Disposition' => 'attachment; filename="'.$safe.'"',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
            // Never buffer on the proxy: the whole point is streaming.
            'X-Accel-Buffering' => 'no',
        ], $headers));
    }

    /**
     * Selected files streamed back as one zip. Entries are stored uncompressed (media is already
     * compressed) and written as they are read, so the archive size never lands in memory.
     *
     * @param  iterable<int, array{storage_key: string, filename: string}>  $files
     * @param  (callable(array{storage_key: string, filename: string}): void)|null  $afterEach  run once an entry is fully written (audit logging)
     */
    public function zip(string $zipName, iterable $files, ?callable $afterEach = null): StreamedResponse
    {
        $safe = str_replace(['"', "\r", "\n"], '', $zipName);

        return response()->stream(function () use ($files, $afterEach) {
            $zip = new StreamingZip(fopen('php://output', 'wb'));
            foreach ($files as $file) {
                $zip->add($file['filename'], $this->storage->readChunks($file['storage_key']));
                if ($afterEach !== null) {
                    $afterEach($file);
                }
            }
            $zip->finish();
        }, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$safe.'"',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
            // Never buffer a zip on the proxy: the whole point is streaming.
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
