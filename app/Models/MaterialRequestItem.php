<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialRequestItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'material_request_id',
        'equipment_item_id',
        'qty_requested',
        'qty_approved',
        'qty_issued',
        'qty_returned',
        'status',
        'note',
        'issue_proof_path',
        'issue_condition',
        'issued_at',
        'issued_by',
        'return_proof_path',
        'return_condition',
        'returned_at',
        'received_by',
    ];

    protected function casts(): array
    {
        return [
            'qty_requested' => 'integer',
            'qty_approved' => 'integer',
            'qty_issued' => 'integer',
            'qty_returned' => 'integer',
            'issued_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(MaterialRequest::class, 'material_request_id');
    }

    public function equipmentItem(): BelongsTo
    {
        return $this->belongsTo(EquipmentItem::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
