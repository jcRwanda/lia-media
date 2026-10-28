<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentCheck extends Model
{
    public const PICKUP_CONFIRM = 'pickup_confirm';

    public const ISSUE_CHECK = 'issue_check';

    public const RETURN_CHECK = 'return_check';

    protected $fillable = [
        'equipment_item_id', 'material_request_id', 'material_request_item_id', 'type', 'actor_id',
        'borrower_name', 'verified_via', 'serial_matches', 'serial_seen', 'notes', 'flagged', 'flag_reason',
        'ip', 'user_agent',
    ];

    protected function casts(): array
    {
        return ['serial_matches' => 'boolean', 'flagged' => 'boolean'];
    }

    public function equipmentItem(): BelongsTo
    {
        return $this->belongsTo(EquipmentItem::class);
    }

    public function materialRequest(): BelongsTo
    {
        return $this->belongsTo(MaterialRequest::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function present(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'material_request_id' => $this->material_request_id,
            'material_request_item_id' => $this->material_request_item_id,
            'actor' => $this->actor?->name,
            'borrower_name' => $this->borrower_name,
            'verified_via' => $this->verified_via,
            'serial_matches' => $this->serial_matches,
            'serial_seen' => $this->serial_seen,
            'notes' => $this->notes,
            'flagged' => $this->flagged,
            'flag_reason' => $this->flag_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
