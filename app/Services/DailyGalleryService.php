<?php

namespace App\Services;

use App\Models\Album;
use App\Models\Asset;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;

/**
 * One album per calendar day (gallery timezone, default Africa/Kigali), keyed by capture date when
 * known and falling back to upload date. Day galleries are system-managed: uploads are filed into
 * them automatically and `gallery:backfill` groups existing assets. Membership is idempotent, so the
 * backfill and repeated uploads are always safe to run.
 */
class DailyGalleryService
{
    public function __construct(private SettingsService $settings) {}

    public function timezone(): string
    {
        return (string) ($this->settings->get('gallery_timezone') ?: 'Africa/Kigali');
    }

    public function enabled(): bool
    {
        return (bool) $this->settings->get('daily_galleries_enabled');
    }

    /** The calendar day an asset belongs to, in the gallery timezone. */
    public function dayFor(Asset $asset): CarbonImmutable
    {
        $at = $asset->captured_at ?? $asset->created_at ?? now();

        return CarbonImmutable::parse($at)->setTimezone($this->timezone())->startOfDay();
    }

    /** Today, in the gallery timezone. */
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone())->startOfDay();
    }

    /** Gallery title, e.g. "Monday, 19 Oct 2026". */
    public function titleFor(CarbonInterface $day): string
    {
        return $day->format('l, j M Y');
    }

    /** Find or create the gallery for a day (restores a soft-deleted one rather than fighting the unique index). */
    public function forDay(CarbonInterface $day, ?User $creator = null): Album
    {
        $date = $day->toDateString();
        if ($album = Album::withTrashed()->where('day', $date)->first()) {
            if ($album->trashed()) {
                $album->restore();
            }

            return $album;
        }

        try {
            return Album::query()->create([
                'title' => $this->titleFor($day), 'kind' => Album::KIND_DAILY, 'day' => $date, 'created_by' => $creator?->id,
            ]);
        } catch (QueryException) {
            return Album::withTrashed()->where('day', $date)->firstOrFail(); // lost a create race
        }
    }

    /** File an asset into its day gallery (idempotent). Returns null when daily galleries are off. */
    public function assign(Asset $asset, ?User $actor = null): ?Album
    {
        if (! $this->enabled()) {
            return null;
        }

        return $this->attach($this->forDay($this->dayFor($asset), $actor), $asset, $actor);
    }

    /** Attach once, preserving the gallery order for anyone who has reordered it. */
    public function attach(Album $album, Asset $asset, ?User $actor = null): Album
    {
        if (! $album->assets()->whereKey($asset->id)->exists()) {
            $album->assets()->attach($asset->id, [
                'position' => (int) $album->assets()->max('album_asset.position') + 1,
                'added_by' => $actor?->id, 'added_at' => now(),
            ]);
            $album->touch();
        }

        return $album;
    }

    /**
     * Group existing assets into day galleries. Idempotent: running it again attaches nothing.
     *
     * @return array{assets: int, attached: int, galleries: int}
     */
    public function backfill(?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $assets = 0;
        $attached = 0;
        Asset::query()
            ->when($from, fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($to, fn ($q, $d) => $q->where('created_at', '<=', $d))
            ->chunkById(500, function ($rows) use (&$assets, &$attached) {
                foreach ($rows as $asset) {
                    $gallery = $this->forDay($this->dayFor($asset));
                    $assets++;
                    if (! $gallery->assets()->whereKey($asset->id)->exists()) {
                        $this->attach($gallery, $asset);
                        $attached++;
                    }
                }
            });

        return ['assets' => $assets, 'attached' => $attached, 'galleries' => Album::query()->daily()->count()];
    }
}
