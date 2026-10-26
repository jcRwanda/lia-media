<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetDerivative;
use App\Storage\ObjectStorage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Thumbnails / previews / video posters are generated in the browser and uploaded here as separate objects. */
class DerivativeService
{
    public function __construct(private ObjectStorage $storage) {}

    public static function key(Asset $a, string $type, string $mime): string
    {
        if ($mime === 'video/mp4') {
            return "derivatives/{$a->uuid}/{$type}.mp4";
        }

        return "derivatives/{$a->uuid}/{$type}.".($mime === 'image/jpeg' ? 'jpg' : 'webp');
    }

    public function generatePreview(Asset $asset): ?array
    {
        if ($asset->kind !== 'video' || ! $this->isTranscodableVideo($asset)) {
            return null;
        }

        $source = $this->storage->presignGet($asset->storage_key, 3600, $asset->original_filename, true);
        $tmpIn = tempnam(sys_get_temp_dir(), 'video-in-');
        $tmpOut = tempnam(sys_get_temp_dir(), 'video-out-');
        unlink($tmpIn);
        unlink($tmpOut);
        $in = $tmpIn.'.mp4';
        $out = $tmpOut.'.mp4';

        $cmd = [
            'ffmpeg', '-y', '-hide_banner', '-loglevel', 'error', '-i', $source,
            '-vf', 'scale=trunc(iw/2)*2:trunc(ih/2)*2,format=yuv420p',
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '28', '-movflags', '+faststart',
            '-c:a', 'aac', '-movflags', '+faststart', $out,
        ];
        $escaped = [];
        foreach ($cmd as $part) {
            $escaped[] = escapeshellarg($part);
        }
        $code = 0;
        $output = [];
        exec(implode(' ', $escaped).' 2>&1', $output, $code);
        if ($code !== 0 || ! is_file($out) || filesize($out) === 0) {
            @unlink($in);
            @unlink($out);

            return null;
        }

        $body = file_get_contents($out);
        if ($body === false) {
            @unlink($in);
            @unlink($out);

            return null;
        }
        $key = self::key($asset, 'preview', 'video/mp4');
        $this->storage->putFile($key, 'video/mp4', $body);
        @unlink($out);

        $head = $this->storage->head($key);
        if (! $head) {
            throw new RuntimeException('Preview video was not stored.');
        }

        $deriv = AssetDerivative::query()->updateOrCreate(['asset_id' => $asset->id, 'type' => 'preview'], [
            'storage_key' => $key, 'mime_type' => 'video/mp4', 'width' => $asset->width ?? 1280, 'height' => $asset->height ?? 720, 'size_bytes' => $head['size'], 'status' => 'ready',
        ]);

        return $deriv->only(['type', 'status', 'width', 'height', 'size_bytes']);
    }

    private function isTranscodableVideo(Asset $asset): bool
    {
        return in_array($asset->mime_type, ['video/mp4', 'video/quicktime', 'video/x-m4v'], true) || str_starts_with($asset->mime_type, 'video/');
    }

    public function initiate(Asset $asset, array $d): array
    {
        $cfg = config("media.derivatives.{$d['type']}");
        if (! in_array($d['mime_type'], $cfg['mimes'], true)) {
            throw ValidationException::withMessages(['mime_type' => 'Derivatives must be WebP, JPEG, or MP4.']);
        }
        if ($d['type'] === 'preview' && $d['mime_type'] === 'video/mp4' && $asset->kind !== 'video') {
            throw ValidationException::withMessages(['type' => 'Video previews are for videos only.']);
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
