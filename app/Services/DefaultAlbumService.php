<?php

namespace App\Services;

use App\Models\Album;
use App\Models\User;

/**
 * The catch-all album new uploads fall back to when no album is chosen. Exactly one album is flagged
 * `is_default` at a time; it is created on demand so uploads always have a home.
 */
class DefaultAlbumService
{
    public function __construct(private SettingsService $settings) {}

    public function ensure(?User $creator = null): Album
    {
        $uuid = trim((string) $this->settings->get('default_album_uuid'));
        if ($uuid !== '' && ($album = Album::query()->where('uuid', $uuid)->first())) {
            return $this->mark($album);
        }

        if ($album = Album::query()->where('is_default', true)->first()) {
            return $album;
        }

        $name = trim((string) $this->settings->get('default_album_name')) ?: 'Unsorted';
        $album = Album::query()->where('title', $name)->where('day', null)->first()
            ?? Album::query()->create(['title' => $name, 'kind' => Album::KIND_CURATED, 'created_by' => $creator?->id]);

        return $this->mark($album);
    }

    private function mark(Album $album): Album
    {
        if ($album->is_default) {
            return $album;
        }
        Album::query()->where('is_default', true)->update(['is_default' => false]);
        $album->update(['is_default' => true]);

        return $album;
    }
}
