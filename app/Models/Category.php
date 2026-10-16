<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = ['name', 'slug', 'parent_id'];

    protected function casts(): array
    {
        return [];
    }

    public function parent(): BelongsTo { return $this->belongsTo(Category::class, 'parent_id'); }
    public function assets(): HasMany { return $this->hasMany(Asset::class); }
}
