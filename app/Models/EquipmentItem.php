<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EquipmentItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'category',
        'serial_number',
        'asset_tag',
        'condition',
        'total_qty',
        'allowed_for_trainees',
        'photo_path',
    ];

    protected function casts(): array
    {
        return [
            'total_qty' => 'integer',
            'allowed_for_trainees' => 'boolean',
        ];
    }

    public function materialRequestItems(): HasMany
    {
        return $this->hasMany(MaterialRequestItem::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(EquipmentBlock::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(EquipmentItemImage::class)->orderBy('sort_order');
    }
}
