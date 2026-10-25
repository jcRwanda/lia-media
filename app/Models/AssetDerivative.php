<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetDerivative extends Model
{
    protected $fillable = ['asset_id', 'type', 'storage_key', 'mime_type', 'width', 'height', 'size_bytes', 'status'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
