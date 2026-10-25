<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialRequestAudit extends Model
{
    use HasFactory;

    protected $fillable = [
        'material_request_id',
        'actor_id',
        'actor_type',
        'action',
        'properties',
        'ip',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(MaterialRequest::class, 'material_request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
