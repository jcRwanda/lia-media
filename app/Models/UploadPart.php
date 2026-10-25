<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UploadPart extends Model
{
    protected $fillable = ['upload_session_id', 'part_number', 'etag', 'size_bytes'];

    protected function casts(): array
    {
        return ['part_number' => 'integer', 'size_bytes' => 'integer'];
    }
}
