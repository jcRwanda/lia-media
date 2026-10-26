<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApiClient extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'key_hash', 'prefill_secret', 'allowed_origin', 'webhook_url', 'active'];

    protected $hidden = ['key_hash', 'prefill_secret'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'prefill_secret' => 'encrypted',
        ];
    }

    public function materialRequests(): HasMany
    {
        return $this->hasMany(MaterialRequest::class);
    }

    public function hasWebhook(): bool
    {
        return filled($this->webhook_url);
    }
}
