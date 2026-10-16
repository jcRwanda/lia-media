<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetDerivative;
use App\Storage\ObjectStorage;
use Illuminate\Validation\ValidationException;

/** Thumbnails / previews / video posters are generated in the browser and uploaded here as separate objects. */
class DerivativeService
{
    public function __construct(private ObjectStorage $storage) {}

    public static function key(Asset $a, string $type, string $mime): string
    {
        return "derivatives/{$a->uuid}/{$type}.".($mime === 'image/jpeg' ? 'jpg' : 'webp');
    }

    public function initiate(Asset $asset, array $d): array
    {
        $cfg = config("media.derivatives.{$d['type']}");
        if (! in_array($d['mime_type'], $cfg['mimes'], true)) {
            throw ValidationException::withMessages(['mime_type' => 'Derivatives must be WebP or JPEG.']);
        }
        if (max($d['width'], $d['height']) > $cfg['max']) {
            throw ValidationException::withMessages(['width' => "A {$d['type']} may be at most {$cfg['max']}px on its long side."]);
        }
        if ($d['type'] === 'poster' && $asset->kind !== 'video') {
            throw ValidationException::withMessages(['type' => 'Posters are for videos.']);
        }
        $key = self::key($asset, $d['type'], $d['mime_type']);
        $deriv = AssetDerivative::query()->updateOrCreate(['asset_id' => $asset->id, 'type' => $d['type']], [
            'storage_key' => $key, 'mime_type' => $d['mime_type'], 'width' => $d['width'], 'height' => $d['height'], 'size_bytes' => $d['size_bytes'], 'status' => 'pending',
        ]);

        return ['derivative' => $deriv->only(['type', 'status']), 'put' => $this->storage->presignPut($key, $d['mime_type'], (int) $d['size_bytes'], 900, $d['sha256_base64'] ?? null)];
    }

    public function complete(Asset $asset, string $type): AssetDerivative
    {
        $d = $asset->derivatives()->where('type', $type)->firstOrFail();
        $head = $this->storage->head($d->storage_key);
        if (! $head || $head['size'] !== (int) $d->size_bytes) {
            $d->update(['status' => 'failed']);
            throw ValidationException::withMessages(['derivative' => 'Derivative not found in storage or size mismatch.']);
        }
        $d->update(['status' => 'ready']);
        if (! $asset->width && $type === 'preview' && $asset->kind === 'image') {
            // keep original dimensions if the browser reported them at upload time; nothing to do otherwise
        }

        return $d;
    }

    /** Short-lived URL for a ready derivative, or null. */
    public function url(Asset $asset, string $type): ?string
    {
        $d = $asset->relationLoaded('derivatives') ? $asset->derivatives->firstWhere('type', $type) : $asset->derivatives()->where('type', $type)->first();

        return $d && $d->status === 'ready' ? $this->storage->presignGet($d->storage_key, config('media.ttl.preview')) : null;
    }
}
