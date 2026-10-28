<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialRequestStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['material_request_id', 'from_status', 'to_status', 'note', 'actor_id', 'actor_type', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function present(bool $withActor = true): array
    {
        return [
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'note' => $this->note,
            'actor' => $withActor ? ($this->actor?->name ?? ($this->actor_type === 'requester' ? 'Requester' : 'System')) : ($this->actor_type === 'requester' ? 'You' : 'LIA team'),
            'actor_type' => $this->actor_type,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
