<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessGrant extends Model
{
    protected $fillable = ['share_link_id', 'share_recipient_id', 'grant_hash', 'ip', 'expires_at', 'revoked_at', 'last_used_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_used_at' => 'datetime'];
    }

    public function shareLink(): BelongsTo
    {
        return $this->belongsTo(ShareLink::class);
    }

    public function isValid(): bool
    {
        return ! $this->revoked_at && $this->expires_at->isFuture();
    }
}
