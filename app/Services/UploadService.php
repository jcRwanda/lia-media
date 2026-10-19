<?php

namespace App\Services;

use App\Jobs\VerifyAssetChecksum;
use App\Models\Album;
use App\Models\Asset;
use App\Models\Tag;
use App\Models\UploadSession;
use App\Models\User;
use App\Storage\ObjectStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Direct-to-R2 uploads. The server only signs URLs and verifies the result; file bytes never pass
 * through PHP (Cloudflare's proxy caps request bodies at 100 MB, and the VPS is small).
 */
class UploadService
{
    public function __construct(
        private ObjectStorage $storage,
        private AuditLogger $audit,
        private SettingsService $settings,
        private DailyGalleryService $galleries,
        private DefaultAlbumService $defaults,
    ) {}

    public static function mimeAllowed(string $mime): bool
    {
        $mime = strtolower($mime);
        if (in_array($mime, config('media.blocked_mimes'), true)) {
            return false;
        }
        foreach (config('media.allowed_mime_prefixes') as $prefix) {
            if (str_starts_with($mime, $prefix)) {
                return true;
            }
        }

        return in_array($mime, config('media.allowed_mimes'), true);
    }

    public static function storageKey(string $uuid, string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $base = Str::slug(pathinfo($filename, PATHINFO_FILENAME)) ?: 'file';

        return sprintf('originals/%s/%s/%s.%s', now()->format('Y/m'), $uuid, Str::limit($base, 80, ''), $ext ?: 'bin');
    }

    public function findDuplicate(string $sha256, int $size): ?Asset
    {
        return Asset::query()->where('sha256', $sha256)->where('size_bytes', $size)->first();
    }

    /** @return array<string, mixed> */
    public function initiate(User $user, array $data): array
    {
        $sha = strtolower($data['sha256']);
        if ($dup = $this->findDuplicate($sha, (int) $data['size_bytes'])) {
            $this->assignDefaults($dup, $this->withDefaults($data['context'] ?? []), $user);
            $this->audit->log('upload.duplicate', $dup, ['filename' => $data['filename']]);

            return ['status' => 'duplicate', 'asset' => ['uuid' => $dup->uuid, 'title' => $dup->title]];
        }

        $uuid = (string) Str::uuid();
        $multipart = $data['size_bytes'] > config('media.multipart_threshold');
        $session = UploadSession::query()->create([
            'uuid' => $uuid, 'user_id' => $user->id, 'filename' => $data['filename'], 'mime_type' => strtolower($data['mime_type']),
            'size_bytes' => $data['size_bytes'], 'sha256' => $sha, 'storage_key' => self::storageKey($uuid, $data['filename']),
            'mode' => $multipart ? 'multipart' : 'single', 'part_size' => $multipart ? $this->partSize((int) $data['size_bytes']) : null,
            'status' => 'pending', 'context' => $data['context'] ?? [], 'expires_at' => now()->addDays(config('media.upload_session_days')),
        ]);

        if ($multipart) {
            $session->update(['upload_id' => $this->storage->createMultipart($session->storage_key, $session->mime_type), 'status' => 'uploading']);

            return ['status' => 'created', 'upload' => $this->sessionPayload($session)];
        }
        $put = $this->storage->presignPut($session->storage_key, $session->mime_type, $session->size_bytes, config('media.ttl.upload'), base64_encode(hex2bin($sha)));
        $session->update(['status' => 'uploading']);

        return ['status' => 'created', 'upload' => $this->sessionPayload($session), 'put' => $put];
    }

    /** Parts of <=10,000, at least the configured size. */
    public function partSize(int $size): int
    {
        $min = (int) config('media.part_size');
        $needed = (int) ceil($size / 9000);

        return $needed <= $min ? $min : (int) (ceil($needed / 1048576) * 1048576);
    }

    /** @param list<int> $numbers @return array<int, string> */
    public function partUrls(UploadSession $s, array $numbers): array
    {
        $this->assertOpen($s, 'multipart');
        $max = $s->partCount();
        $urls = [];
        foreach (array_unique($numbers) as $n) {
            if ($n < 1 || $n > $max) {
                throw ValidationException::withMessages(['part_numbers' => "Part {$n} is out of range (1-{$max})."]);
            }
            $urls[$n] = $this->storage->presignPart($s->storage_key, $s->upload_id, $n, config('media.ttl.upload'));
        }

        return $urls;
    }

    /** Fresh single PUT URL (e.g. the first one expired before the browser got to it). */
    public function renewPut(UploadSession $s): array
    {
        $this->assertOpen($s, 'single');

        return $this->storage->presignPut($s->storage_key, $s->mime_type, $s->size_bytes, config('media.ttl.upload'), base64_encode(hex2bin($s->sha256)));
    }

    /** Uploaded parts according to storage (authoritative for resume). */
    public function uploadedParts(UploadSession $s): array
    {
        if ($s->mode !== 'multipart' || ! $s->upload_id || ! in_array($s->status, ['pending', 'uploading'], true)) {
            return [];
        }

        return $this->storage->listParts($s->storage_key, $s->upload_id);
    }

    public function complete(UploadSession $s, User $user): Asset
    {
        $this->assertOpen($s);
        if ($s->mode === 'multipart') {
            $parts = $this->uploadedParts($s);
            $expected = $s->partCount();
            $numbers = array_column($parts, 'part_number');
            sort($numbers);
            if ($numbers !== range(1, $expected)) {
                throw ValidationException::withMessages(['upload' => sprintf('Upload is incomplete: %d of %d parts received.', count($parts), $expected)]);
            }
            $this->storage->completeMultipart($s->storage_key, $s->upload_id, $parts);
            DB::transaction(function () use ($s, $parts) {
                foreach ($parts as $p) {
                    $s->parts()->updateOrCreate(['part_number' => $p['part_number']], ['etag' => $p['etag'], 'size_bytes' => $p['size']]);
                }
            });
        }

        $head = $this->storage->head($s->storage_key);
        $error = match (true) {
            ! $head => 'The file was not found in storage. Please upload it again.',
            $head['size'] !== $s->size_bytes => "Size mismatch: expected {$s->size_bytes} bytes, storage has {$head['size']}.",
            $s->mode === 'single' && $head['sha256_base64'] && $head['sha256_base64'] !== base64_encode(hex2bin($s->sha256)) => 'Checksum mismatch.',
            default => null,
        };
        if ($error) {
            if ($head) {
                $this->storage->delete($s->storage_key);
            }
            $s->update(['status' => 'failed', 'error' => $error]);
            $this->audit->log('upload.failed', $s, ['error' => $error]);
            throw ValidationException::withMessages(['upload' => $error]);
        }

        $ctx = $this->withDefaults($s->context ?? []);

        // A parallel upload of the same bytes may have finished first.
        if ($dup = $this->findDuplicate($s->sha256, $s->size_bytes)) {
            $this->storage->delete($s->storage_key);
            $s->update(['status' => 'duplicate', 'asset_id' => $dup->id, 'completed_at' => now()]);
            $this->assignDefaults($dup, $ctx, $user);

            return $dup;
        }

        $asset = DB::transaction(function () use ($s, $user, $ctx, $head) {
            $asset = Asset::query()->create([
                'uuid' => Str::uuid()->toString(), 'kind' => Asset::kindFromMime($s->mime_type),
                'title' => $ctx['title'] ?? Str::limit(pathinfo($s->filename, PATHINFO_FILENAME), 190, ''),
                'original_filename' => $s->filename, 'mime_type' => $s->mime_type, 'size_bytes' => $s->size_bytes,
                'sha256' => $s->sha256, 'storage_key' => $s->storage_key, 'uploaded_by' => $user->id,
                'project_id' => $ctx['project_id'] ?? null, 'cohort_id' => $ctx['cohort_id'] ?? null, 'event_id' => $ctx['event_id'] ?? null,
                'category_id' => $ctx['category_id'] ?? null, 'credit' => $ctx['credit'] ?? null,
                'width' => $ctx['width'] ?? null, 'height' => $ctx['height'] ?? null, 'duration_ms' => $ctx['duration_ms'] ?? null,
                'captured_at' => $ctx['captured_at'] ?? null, 'review_state' => $this->initialReviewState(),
                'metadata' => ['checksum_verified' => $s->mode === 'single' && (bool) $head['sha256_base64'], 'checksum_method' => $s->mode === 'single' ? 'storage_put_checksum' : 'pending_server_hash'],
            ]);
            $tagNames = ! empty($ctx['tags']) ? $ctx['tags'] : $this->defaultTags();
            if ($tagNames) {
                $asset->tags()->sync(collect($tagNames)->map(fn ($name) => Tag::query()->firstOrCreate(['slug' => Str::slug($name)], ['name' => $name])->id));
            }
            $s->update(['status' => 'completed', 'asset_id' => $asset->id, 'completed_at' => now()]);

            return $asset;
        });
        $this->assignDefaults($asset, $ctx, $user);
        $this->audit->log('asset.uploaded', $asset, ['size' => $asset->size_bytes, 'mode' => $s->mode]);
        if (! ($asset->metadata['checksum_verified'] ?? false)) {
            VerifyAssetChecksum::dispatch($asset->id);
        }

        return $asset;
    }

    public function abort(UploadSession $s): void
    {
        if (in_array($s->status, ['completed', 'duplicate'], true)) {
            throw ValidationException::withMessages(['upload' => 'This upload is already finished.']);
        }
        if ($s->mode === 'multipart' && $s->upload_id) {
            $this->storage->abortMultipart($s->storage_key, $s->upload_id);
        } elseif ($this->storage->head($s->storage_key)) {
            $this->storage->delete($s->storage_key);
        }
        $s->update(['status' => 'aborted']);
    }

    /** Abort sessions past their expiry (hourly via the scheduler). */
    public function pruneExpired(): int
    {
        $n = 0;
        UploadSession::query()->whereIn('status', ['pending', 'uploading'])->where('expires_at', '<', now())->each(function (UploadSession $s) use (&$n) {
            $this->abort($s);
            $s->update(['error' => 'Expired']);
            $n++;
        });

        return $n;
    }

    /** Fill in the configured upload defaults (date, review state, tags) for a new upload. */
    private function withDefaults(array $ctx): array
    {
        if (blank($ctx['captured_at'] ?? null) && (bool) $this->settings->get('default_captured_at')) {
            $ctx['captured_at'] = now()->toIso8601String();
        }

        return $ctx;
    }

    private function initialReviewState(): string
    {
        $state = (string) $this->settings->get('default_review_state');

        return in_array($state, Asset::REVIEW_STATES, true) ? $state : 'unreviewed';
    }

    /** @return list<string> */
    private function defaultTags(): array
    {
        return collect(explode(',', (string) $this->settings->get('default_tags')))->map(fn ($t) => trim($t))->filter()->unique()->values()->all();
    }

    /**
     * Album filing for a finalized upload: an explicitly chosen album wins, otherwise the asset
     * falls back to the default album. Every upload is also filed into its day gallery (unless that
     * is turned off), which keeps the automatic daily archive complete.
     */
    private function assignDefaults(Asset $asset, array $ctx, User $user): void
    {
        $default = $this->defaults->ensure($user);
        $album = ! empty($ctx['album_id']) ? Album::query()->find($ctx['album_id']) : $default;
        if ($album) {
            $this->attachToAlbum($asset, $album, $user);
        }
        $this->galleries->assign($asset, $user);
    }

    private function attachToAlbum(Asset $asset, Album $album, User $user): void
    {
        if (! $album->assets()->whereKey($asset->id)->exists()) {
            $album->assets()->attach($asset->id, ['position' => (int) $album->assets()->max('album_asset.position') + 1, 'added_by' => $user->id, 'added_at' => now()]);
        }
    }

    private function assertOpen(UploadSession $s, ?string $mode = null): void
    {
        if (! in_array($s->status, ['pending', 'uploading'], true)) {
            throw ValidationException::withMessages(['upload' => "This upload is {$s->status}."]);
        }
        if ($s->expires_at->isPast()) {
            throw ValidationException::withMessages(['upload' => 'This upload session has expired. Start again.']);
        }
        if ($mode && $s->mode !== $mode) {
            throw ValidationException::withMessages(['upload' => "Not a {$mode} upload."]);
        }
    }

    public function sessionPayload(UploadSession $s): array
    {
        return [
            'uuid' => $s->uuid, 'filename' => $s->filename, 'size_bytes' => $s->size_bytes, 'mime_type' => $s->mime_type, 'mode' => $s->mode,
            'part_size' => $s->part_size, 'part_count' => $s->partCount(), 'status' => $s->status, 'error' => $s->error,
            'asset_uuid' => $s->asset?->uuid, 'expires_at' => $s->expires_at?->toIso8601String(), 'created_at' => $s->created_at?->toIso8601String(),
        ];
    }
}
