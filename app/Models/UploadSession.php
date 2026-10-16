<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class UploadSession extends Model
{
    protected $fillable = ['uuid', 'user_id', 'filename', 'mime_type', 'size_bytes', 'sha256', 'storage_key', 'mode', 'upload_id', 'part_size', 'status', 'error', 'asset_id', 'context', 'expires_at', 'completed_at'];

    protected $hidden = ['storage_key', 'upload_id'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'part_size' => 'integer', 'context' => 'array', 'expires_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (UploadSession $s) => $s->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function partCount(): int
    {
        return $this->mode === 'multipart' ? (int) ceil($this->size_bytes / max(1, $this->part_size)) : 1;
    }

    public function parts(): HasMany { return $this->hasMany(UploadPart::class)->orderBy('part_number'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function asset(): BelongsTo { return $this->belongsTo(Asset::class); }
}
