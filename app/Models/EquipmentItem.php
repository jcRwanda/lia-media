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

    protected static function booted(): void
    {
        static::creating(function (EquipmentItem $item) {
            if (! $item->qr_token) {
                $item->qr_token = \Illuminate\Support\Str::random(32);
            }
        });
    }

    public function qrUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/q/'.$this->qr_token;
    }

    /** Related items that usually go with this one (e.g. camera → battery, charger, lens). */
    public function accessories(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(EquipmentItem::class, 'equipment_item_accessories', 'equipment_item_id', 'accessory_id')
            ->withTimestamps()->orderBy('name');
    }

    public function checks(): HasMany
    {
        return $this->hasMany(EquipmentCheck::class);
    }

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
