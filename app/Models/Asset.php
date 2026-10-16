<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Asset extends Model
{
    use SoftDeletes;

    public const KINDS = ['image', 'video', 'audio', 'document', 'other'];

    public const REVIEW_STATES = ['unreviewed', 'approved', 'published', 'restricted', 'archived'];

    /** States whose assets may appear in public / access-code shares. */
    public const SHAREABLE_STATES = ['approved', 'published'];

    protected $fillable = [
        'uuid', 'kind', 'title', 'description', 'original_filename', 'mime_type', 'size_bytes', 'sha256', 'storage_key', 'storage_status',
        'width', 'height', 'duration_ms', 'captured_at', 'credit', 'uploaded_by', 'project_id', 'cohort_id', 'event_id', 'category_id',
        'review_state', 'review_note', 'reviewed_by', 'reviewed_at', 'consent_status', 'metadata',
    ];

    protected $hidden = ['storage_key'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'width' => 'integer', 'height' => 'integer', 'duration_ms' => 'integer', 'captured_at' => 'datetime', 'reviewed_at' => 'datetime', 'metadata' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(fn (Asset $a) => $a->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public static function kindFromMime(string $mime): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            in_array($mime, ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'text/plain'], true) => 'document',
            default => 'other',
        };
    }

    public function isShareable(): bool
    {
        return in_array($this->review_state, self::SHAREABLE_STATES, true) && $this->consent_status !== 'withdrawn';
    }

    public function derivatives(): HasMany { return $this->hasMany(AssetDerivative::class); }
    public function tags(): BelongsToMany { return $this->belongsToMany(Tag::class); }
    public function albums(): BelongsToMany { return $this->belongsToMany(Album::class)->withPivot('position'); }
    public function people(): BelongsToMany { return $this->belongsToMany(Person::class)->withPivot('consent_record_id'); }
    public function publications(): HasMany { return $this->hasMany(PublicationUsage::class); }
    public function reviewEvents(): HasMany { return $this->hasMany(ReviewEvent::class); }
    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function cohort(): BelongsTo { return $this->belongsTo(Cohort::class); }
    public function event(): BelongsTo { return $this->belongsTo(Event::class); }
    public function category(): BelongsTo { return $this->belongsTo(Category::class); }
}
