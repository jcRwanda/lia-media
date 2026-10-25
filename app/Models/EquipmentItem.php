<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
        'photo_path',
    ];

    protected function casts(): array
    {
        return [
            'total_qty' => 'integer',
        ];
    }

    public function materialRequestItems()
    {
        return $this->hasMany(MaterialRequestItem::class);
    }
}
