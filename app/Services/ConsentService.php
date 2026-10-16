<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\ConsentRecord;
use App\Models\User;

class ConsentService
{
    public function __construct(private ReviewService $review, private AuditLogger $audit) {}

    /** covered: everyone pictured has active consent; withdrawn beats missing beats covered. */
    public function recompute(Asset $asset): string
    {
        $people = $asset->people()->get();
        if ($people->isEmpty()) {
            if (in_array($asset->consent_status, ['covered', 'missing', 'withdrawn'], true)) {
                $asset->update(['consent_status' => 'unknown']);
            }

            return $asset->consent_status;
        }
        $records = ConsentRecord::query()->whereIn('id', $people->pluck('pivot.consent_record_id')->filter())->get()->keyBy('id');
        $status = 'covered';
        foreach ($people as $p) {
            $r = $records->get($p->pivot->consent_record_id);
            if ($r && $r->withdrawn_at) {
                $status = 'withdrawn';
                break;
            }
            if (! $r || ! $r->isActive()) {
                $status = 'missing';
            }
        }
        $asset->update(['consent_status' => $status]);

        return $status;
    }

    /** Withdrawal restricts every approved/published item that relied on this consent. */
    public function withdraw(ConsentRecord $record, User $actor, string $reason): int
    {
        $record->update(['withdrawn_at' => now(), 'withdrawn_reason' => $reason]);
        $assets = Asset::query()->whereHas('people', fn ($q) => $q->where('asset_person.consent_record_id', $record->id))->get();
        $restricted = 0;
        foreach ($assets as $a) {
            $this->recompute($a);
            if (in_array($a->review_state, ['approved', 'published', 'unreviewed'], true)) {
                $this->review->transition($a, 'restricted', $actor, "Consent withdrawn: {$reason}", true);
                $restricted++;
            }
        }
        $this->audit->log('consent.withdrawn', $record, ['reason' => $reason, 'assets_restricted' => $restricted], $actor);

        return $restricted;
    }
}
