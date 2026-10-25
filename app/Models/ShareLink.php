<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ShareLink extends Model
{
    public const MODES = ['public', 'access_code', 'restricted'];

    protected $fillable = ['uuid', 'token_hash', 'shareable_type', 'shareable_id', 'mode', 'access_code_hash', 'title', 'message', 'allow_download', 'include_originals', 'expires_at', 'max_uses', 'use_count', 'revoked_at', 'revoked_by', 'created_by', 'last_accessed_at'];

    protected $hidden = ['token_hash', 'access_code_hash'];

    protected function casts(): array
    {
        return ['allow_download' => 'boolean', 'include_originals' => 'boolean', 'expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_accessed_at' => 'datetime', 'max_uses' => 'integer', 'use_count' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(fn (ShareLink $s) => $s->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isLive(): bool
    {
        return ! $this->revoked_at && (! $this->expires_at || $this->expires_at->isFuture()) && (! $this->max_uses || $this->use_count < $this->max_uses);
    }

    public function target(): Album|Asset|null
    {
        return $this->shareable_type === 'album' ? Album::query()->find($this->shareable_id) : Asset::query()->find($this->shareable_id);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(ShareRecipient::class);
    }

    public function grants(): HasMany
    {
        return $this->hasMany(AccessGrant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
