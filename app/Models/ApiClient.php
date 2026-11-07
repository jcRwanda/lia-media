<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Hash;

class ApiClient extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'key_hash', 'prefill_secret', 'allowed_origin', 'webhook_url', 'active', 'allows_alumni_certificate_exempt'];

    protected $hidden = ['key_hash', 'prefill_secret'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'allows_alumni_certificate_exempt' => 'boolean',
            'prefill_secret' => 'encrypted',
        ];
    }

    /**
     * True when the decrypted prefill secret is the same plaintext as this client's API key.
     * Does not reveal either value.
     */
    public function prefillSecretMatchesApiKey(): bool
    {
        try {
            $secret = $this->prefill_secret;
        } catch (\Throwable) {
            return false;
        }

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        return Hash::check($secret, (string) $this->key_hash);
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
