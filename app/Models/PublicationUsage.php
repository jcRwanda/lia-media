<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PublicationUsage extends Model
{
    protected $fillable = ['asset_id', 'channel', 'url', 'title', 'published_on', 'notes', 'recorded_by'];

    protected function casts(): array
    {
        return ['published_on' => 'date'];
    }

    public function asset(): BelongsTo { return $this->belongsTo(Asset::class); }
    public function recorder(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
}
