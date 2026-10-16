<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tag extends Model
{
    protected $fillable = ['name', 'slug'];

    protected function casts(): array
    {
        return [];
    }

    public function assets(): BelongsToMany { return $this->belongsToMany(Asset::class); }
}
