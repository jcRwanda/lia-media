<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentBlock extends Model
{
    use HasFactory;

    protected $fillable = [
        'equipment_item_id',
        'starts_on',
        'ends_on',
        'reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'equipment_item_id' => 'integer',
            'created_by' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function equipmentItem(): BelongsTo
    {
        return $this->belongsTo(EquipmentItem::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
