<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiClient extends Model
{
    protected $fillable = ['name', 'key_hash', 'allowed_origin', 'active'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }
}
