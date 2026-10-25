<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class MaterialRequest extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'reference_code',
        'requester_type',
        'full_name',
        'phone',
        'email',
        'national_id',
        'province_res',
        'district_res',
        'sector_res',
        'cell_res',
        'residential_address',
        'emergency_contact_name',
        'emergency_contact_phone',
        'purpose_project_description',
        'project_province',
        'project_district',
        'project_sector',
        'project_cell',
        'project_location',
        'needed_from',
        'return_by',
        'terms_accepted',
        'program_cohort',
        'graduation_completion_year',
        'student_alumni_id',
        'project_title',
        'project_type',
        'expected_outcome',
        'portfolio_social_link',
        'status',
        'review_note',
        'reviewed_by',
        'reviewed_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'closed_by',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'terms_accepted' => 'boolean',
            'needed_from' => 'date',
            'return_by' => 'date',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $request) {
            if (! $request->uuid) {
                $request->uuid = (string) Str::uuid();
            }
            if (! $request->reference_code) {
                $request->reference_code = 'MR-'.strtoupper(Str::random(8));
            }
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(MaterialRequestItem::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(MaterialRequestAudit::class);
    }

    public function isOverdue(): bool
    {
        if (! $this->return_by) {
            return false;
        }
        if (in_array($this->status, ['closed', 'rejected'], true)) {
            return false;
        }

        return now()->greaterThan($this->return_by->endOfDay());
    }
}
