<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsentRecord extends Model
{
    protected $fillable = ['person_id', 'scope', 'event_id', 'signed_on', 'expires_on', 'guardian_name', 'form_storage_key', 'withdrawn_at', 'withdrawn_reason', 'notes', 'recorded_by'];

    protected function casts(): array
    {
        return ['signed_on' => 'date', 'expires_on' => 'date', 'withdrawn_at' => 'datetime'];
    }

    protected $hidden = ['form_storage_key'];
    public function person(): BelongsTo { return $this->belongsTo(Person::class); }
    public function event(): BelongsTo { return $this->belongsTo(Event::class); }
    public function isActive(): bool { return ! $this->withdrawn_at && (! $this->expires_on || $this->expires_on->endOfDay()->isFuture()); }
}
