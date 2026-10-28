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
        'api_client_id',
        'source',
        'source_detail',
        'requester_type',
        'full_name',
        'phone',
        'email',
        'project_name',
        'national_id',
        'province_res',
        'district_res',
        'sector_res',
        'cell_res',
        'village_res',
        'residential_address',
        'emergency_contact_name',
        'emergency_contact_phone',
        'relationship',
        'purpose_project_description',
        'project_province',
        'project_district',
        'project_sector',
        'project_cell',
        'project_village',
        'project_location',
        'shoot_location',
        'shoot_type',
        'needed_from',
        'return_by',
        'terms_accepted',
        'terms_credit',
        'consent_declaration',
        'program_cohort',
        'cohort_id',
        'graduation_completion_year',
        'student_alumni_id',
        'project_title',
        'project_type',
        'expected_outcome',
        'portfolio_social_link',
        'id_document_path',
        'id_document_mime',
        'id_document_name',
        'signature_path',
        'signature_date',
        'status',
        'final_work_status',
        'final_work_submitted_at',
        'final_work_reviewed_at',
        'final_work_reviewed_by',
        'final_work_review_note',
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
            'terms_credit' => 'boolean',
            'consent_declaration' => 'boolean',
            'needed_from' => 'date',
            'return_by' => 'date',
            'signature_date' => 'date',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'closed_at' => 'datetime',
            'final_work_submitted_at' => 'datetime',
            'final_work_reviewed_at' => 'datetime',
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

    public function apiClient(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MaterialRequestItem::class);
    }

    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
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

    public function statusHistories(): HasMany
    {
        return $this->hasMany(MaterialRequestStatusHistory::class)->orderBy('id');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(MaterialRequestAudit::class);
    }

    public function finalWorks(): HasMany
    {
        return $this->hasMany(MaterialRequestFinalWork::class);
    }

    public function finalWorkReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'final_work_reviewed_by');
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
