<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaterialRequestFinalWork extends Model
{
    protected $fillable = [
        'material_request_id',
        'link',
        'file_path',
        'file_name',
        'file_mime',
        'note',
        'submitted_by_source',
    ];

    public function materialRequest(): BelongsTo
    {
        return $this->belongsTo(MaterialRequest::class);
    }
}
