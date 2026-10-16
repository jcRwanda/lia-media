<?php

namespace App\Services;

use App\Models\Asset;
use App\Storage\ObjectStorage;

/** JSON shapes for assets. Media URLs are short-lived presigned GETs, never permanent links. */
class AssetPresenter
{
    public function __construct(private ObjectStorage $storage) {}

    public function derivUrl(Asset $a, string $type): ?string
    {
        $d = $a->derivatives->firstWhere('type', $type);

        return $d && $d->status === 'ready' ? $this->storage->presignGet($d->storage_key, config('media.ttl.preview')) : null;
    }

    public function card(Asset $a): array
    {
        return [
            'uuid' => $a->uuid, 'kind' => $a->kind, 'title' => $a->title, 'mime_type' => $a->mime_type, 'size_bytes' => $a->size_bytes,
            'width' => $a->width, 'height' => $a->height, 'duration_ms' => $a->duration_ms, 'review_state' => $a->review_state,
            'consent_status' => $a->consent_status, 'storage_status' => $a->storage_status, 'created_at' => $a->created_at?->toIso8601String(),
            'captured_at' => $a->captured_at?->toIso8601String(),
            'thumb_url' => $this->derivUrl($a, 'thumb'),
        ];
    }

    public function detail(Asset $a): array
    {
        $a->loadMissing(['derivatives', 'tags', 'albums:id,uuid,title', 'uploader:id,name', 'reviewer:id,name', 'project:id,name', 'event:id,title', 'cohort:id,name', 'category:id,name',
            'publications.recorder:id,name', 'reviewEvents.actor:id,name', 'people']);

        return $this->card($a) + [
            'description' => $a->description, 'original_filename' => $a->original_filename, 'sha256' => $a->sha256, 'credit' => $a->credit,
            'project' => $a->project?->only(['id', 'name']), 'event' => $a->event?->only(['id', 'title']), 'cohort' => $a->cohort?->only(['id', 'name']), 'category' => $a->category?->only(['id', 'name']),
            'project_id' => $a->project_id, 'event_id' => $a->event_id, 'cohort_id' => $a->cohort_id, 'category_id' => $a->category_id,
            'tags' => $a->tags->pluck('name')->values(), 'albums' => $a->albums->map->only(['uuid', 'title'])->values(),
            'uploader' => $a->uploader?->name, 'reviewer' => $a->reviewer?->name, 'reviewed_at' => $a->reviewed_at?->toIso8601String(), 'review_note' => $a->review_note,
            'metadata' => $a->metadata,
            'preview_url' => $this->derivUrl($a, 'preview') ?? $this->derivUrl($a, 'poster') ?? $this->derivUrl($a, 'thumb'),
            'poster_url' => $this->derivUrl($a, 'poster'),
            'derivatives' => $a->derivatives->map->only(['type', 'status', 'width', 'height', 'size_bytes'])->values(),
            'people' => $a->people->map(fn ($p) => ['id' => $p->id, 'full_name' => $p->full_name, 'is_minor' => $p->is_minor, 'consent_record_id' => $p->pivot->consent_record_id])->values(),
            'publications' => $a->publications->sortByDesc('published_on')->map(fn ($p) => ['id' => $p->id, 'channel' => $p->channel, 'url' => $p->url, 'title' => $p->title, 'published_on' => $p->published_on?->toDateString(), 'notes' => $p->notes, 'recorded_by' => $p->recorder?->name])->values(),
            'review_history' => $a->reviewEvents->sortByDesc('id')->map(fn ($e) => ['from' => $e->from_state, 'to' => $e->to_state, 'note' => $e->note, 'actor' => $e->actor?->name, 'at' => $e->created_at?->toIso8601String()])->values(),
        ];
    }
}
