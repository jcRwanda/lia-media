<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Person extends Model
{
    protected $fillable = ['full_name', 'organisation', 'is_minor', 'contact', 'notes'];

    protected function casts(): array
    {
        return ['is_minor' => 'boolean', 'contact' => 'encrypted'];
    }

    protected $table = 'people';

    public function consents(): HasMany
    {
        return $this->hasMany(ConsentRecord::class);
    }

    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class)->withPivot('consent_record_id');
    }
}
