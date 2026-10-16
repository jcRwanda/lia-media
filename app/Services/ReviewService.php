<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\ReviewEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** unreviewed → approved → published, with restricted / archived side states. */
class ReviewService
{
    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        'unreviewed' => ['approved', 'restricted', 'archived'],
        'approved' => ['published', 'restricted', 'archived', 'unreviewed'],
        'published' => ['approved', 'restricted', 'archived'],
        'restricted' => ['unreviewed', 'approved', 'archived'],
        'archived' => ['unreviewed'],
    ];

    public function __construct(private AuditLogger $audit, private SettingsService $settings) {}

    public static function abilityFor(string $from, string $to): string
    {
        return $to === 'published' || $from === 'published' ? 'review.publish' : 'review.approve';
    }

    public function transition(Asset $asset, string $to, User $actor, ?string $note = null, bool $system = false): Asset
    {
        $from = $asset->review_state;
        if ($from === $to) {
            return $asset;
        }
        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw ValidationException::withMessages(['to_state' => "Cannot move from {$from} to {$to}."]);
        }
        if (! $system && ! $actor->canDo(self::abilityFor($from, $to))) {
            throw ValidationException::withMessages(['to_state' => 'Your role cannot make this change.'])->status(403);
        }
        if ($to === 'restricted' && blank($note)) {
            throw ValidationException::withMessages(['note' => 'Say why this item is restricted.']);
        }
        if ($to === 'published' && $this->settings->get('require_consent_for_publish') && in_array($asset->consent_status, ['missing', 'withdrawn'], true)) {
            throw ValidationException::withMessages(['to_state' => 'Consent is '.$asset->consent_status.' for someone in this item. Resolve consent before publishing.']);
        }
        DB::transaction(function () use ($asset, $from, $to, $actor, $note) {
            $asset->update(['review_state' => $to, 'review_note' => $note, 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
            ReviewEvent::query()->create(['asset_id' => $asset->id, 'from_state' => $from, 'to_state' => $to, 'note' => $note, 'actor_id' => $actor->id]);
        });
        $this->audit->log('asset.review', $asset, ['from' => $from, 'to' => $to, 'note' => $note], $actor);

        return $asset;
    }
}
