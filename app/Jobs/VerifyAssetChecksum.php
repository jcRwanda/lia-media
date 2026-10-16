<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Services\AuditLogger;
use App\Storage\ObjectStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Server-side SHA-256 of a stored original (multipart uploads have no whole-object checksum in storage). */
class VerifyAssetChecksum implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 3500;

    public function __construct(public int $assetId) {}

    public function handle(ObjectStorage $storage, AuditLogger $audit): void
    {
        $asset = Asset::query()->find($this->assetId);
        if (! $asset) {
            return;
        }
        $ctx = hash_init('sha256');
        $bytes = 0;
        foreach ($storage->readChunks($asset->storage_key) as $chunk) {
            hash_update($ctx, $chunk);
            $bytes += strlen($chunk);
        }
        $actual = hash_final($ctx);
        $ok = $actual === $asset->sha256 && $bytes === $asset->size_bytes;
        $asset->update(['metadata' => array_merge($asset->metadata ?? [], ['checksum_verified' => $ok, 'checksum_method' => 'server_sha256', 'checksum_checked_at' => now()->toIso8601String()] + ($ok ? [] : ['checksum_actual' => $actual]))]);
        if (! $ok) {
            $asset->update(['storage_status' => 'corrupt']);
            $audit->log('asset.checksum_mismatch', $asset, ['expected' => $asset->sha256, 'actual' => $actual, 'bytes' => $bytes], null, 'system');
        }
    }
}
