<?php

namespace App\Models;

use App\Storage\ObjectStorage;
use App\Support\Roles;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role', 'role_id', 'status', 'must_change_password', 'phone', 'photo_path'];

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

    public function roleModel(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function canDo(string $ability): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        // Check database role first
        $roleModel = $this->relationLoaded('roleModel') ? $this->roleModel : $this->roleModel()->first();
        if ($roleModel) {
            if ($roleModel->is_admin) {
                return true;
            }
            $permissions = $roleModel->getPermissions();
            if (in_array($ability, $permissions, true)) {
                return true;
            }

            return false;
        }

        // Fallback to legacy Roles support
        return Roles::allows($this->role, $ability);
    }

    public function toApi(): array
    {
        $abilities = [];
        $roleModel = $this->relationLoaded('roleModel') ? $this->roleModel : $this->roleModel()->first();
        if ($roleModel) {
            if ($roleModel->is_admin) {
                $abilities = Roles::abilitiesFor(Roles::ADMIN);
            } else {
                $abilities = $roleModel->getPermissions();
            }
        } else {
            $abilities = Roles::abilitiesFor($this->role);
        }

        $photoUrl = null;
        if ($this->photo_path) {
            try {
                $storage = app(ObjectStorage::class);
                if (method_exists($storage, 'temporaryUrl')) {
                    $photoUrl = $storage->temporaryUrl($this->photo_path, now()->addMinutes(60));
                }
            } catch (\Throwable $e) {
                $photoUrl = null;
            }
        }

        return [
            'id' => $this->id, 'name' => $this->name, 'email' => $this->email, 'role' => $this->role,
            'role_id' => $this->role_id,
            'status' => $this->status, 'abilities' => $abilities,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'must_change_password' => (bool) $this->must_change_password,
            'first_login_completed' => (bool) $this->last_login_at && ! $this->must_change_password,
            'phone' => $this->phone ?? null,
            'photo_path' => $this->photo_path ?? null,
            'photo_url' => $photoUrl,
        ];
    }
}
