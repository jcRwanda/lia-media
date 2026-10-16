<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cohort extends Model
{
    protected $fillable = ['name', 'code', 'year', 'project_id'];

    protected function casts(): array
    {
        return [];
    }

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function assets(): HasMany { return $this->hasMany(Asset::class); }
}
