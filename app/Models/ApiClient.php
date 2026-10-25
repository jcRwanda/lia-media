<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiClient extends Model
{
    protected $fillable = ['name', 'key_hash', 'prefill_secret', 'allowed_origin', 'active'];

    protected $hidden = ['key_hash', 'prefill_secret'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'prefill_secret' => 'encrypted',
        ];
    }
}
