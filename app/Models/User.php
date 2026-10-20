<?php

namespace App\Models;

use App\Support\Roles;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role', 'status', 'must_change_password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function canDo(string $ability): bool
    {
        return $this->isActive() && Roles::allows($this->role, $ability);
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id, 'name' => $this->name, 'email' => $this->email, 'role' => $this->role,
            'status' => $this->status, 'abilities' => Roles::abilitiesFor($this->role),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'must_change_password' => (bool) $this->must_change_password,
            'first_login_completed' => (bool) $this->last_login_at && ! $this->must_change_password,
        ];
    }
}
