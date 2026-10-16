<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    protected $fillable = ['title', 'slug', 'occurred_on', 'ends_on', 'location', 'description', 'project_id', 'cohort_id'];

    protected function casts(): array
    {
        return ['occurred_on' => 'date', 'ends_on' => 'date'];
    }

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function cohort(): BelongsTo { return $this->belongsTo(Cohort::class); }
}
