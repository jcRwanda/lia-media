<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReviewEvent extends Model
{
    protected $fillable = ['asset_id', 'from_state', 'to_state', 'note', 'actor_id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public const UPDATED_AT = null;
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }
}
