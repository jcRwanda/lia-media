<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Album extends Model
{
    use SoftDeletes;

    protected $fillable = ['uuid', 'title', 'description', 'project_id', 'cohort_id', 'event_id', 'cover_asset_id', 'created_by'];

    protected static function booted(): void
    {
        static::creating(fn (Album $a) => $a->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class)->withPivot('position', 'added_by', 'added_at')->orderBy('album_asset.position');
    }

    public function cover(): BelongsTo { return $this->belongsTo(Asset::class, 'cover_asset_id'); }
    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function event(): BelongsTo { return $this->belongsTo(Event::class); }
    public function cohort(): BelongsTo { return $this->belongsTo(Cohort::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
