<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShareRecipient extends Model
{
    protected $fillable = ['share_link_id', 'email', 'name', 'token_hash', 'revoked_at', 'last_accessed_at'];

    protected function casts(): array
    {
        return ['revoked_at' => 'datetime', 'last_accessed_at' => 'datetime'];
    }

    public function shareLink(): BelongsTo { return $this->belongsTo(ShareLink::class); }
}
