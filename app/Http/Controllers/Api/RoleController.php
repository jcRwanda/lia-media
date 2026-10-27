<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Services\AuditLogger;
use App\Support\RolePermissionMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RoleController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $roles = Role::query()->orderBy('id')->get();

        return response()->json([
            'data' => $roles->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'slug' => $role->slug,
                'is_system' => (bool) $role->is_system,
                'is_admin' => (bool) $role->is_admin,
                'permissions' => $role->getPermissions(),
            ]),
            'permissions' => RolePermissionMap::grouped(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('roles', 'name')],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string'],
        ]);

        $slug = str($data['name'])->slug()->toString();
        if (Role::query()->where('slug', $slug)->exists()) {
            $slug = $slug.'-'.substr(uniqid(), -6);
        }

        $role = Role::query()->create([
            'name' => $data['name'],
            'slug' => $slug,
            'is_system' => false,
            'is_admin' => false,
            'permissions' => array_values(array_unique($data['permissions'] ?? [])),
        ]);

        $this->audit->log('role.created', $role, ['name' => $role->name]);

        return response()->json(['role' => $role], 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        if ($role->is_admin) {
            throw ValidationException::withMessages(['role' => 'The built-in admin role cannot be modified.']);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120', Rule::unique('roles', 'name')->ignore($role->id)],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string'],
        ]);

        $before = $role->only(['name', 'permissions']);

        if (isset($data['name'])) {
            $role->name = $data['name'];
            if (! $role->is_system) {
                $slug = str($data['name'])->slug()->toString();
                if (Role::query()->where('slug', $slug)->where('id', '!=', $role->id)->exists()) {
                    $slug = $slug.'-'.substr(uniqid(), -6);
                }
                $role->slug = $slug;
            }
        }
        if (isset($data['permissions'])) {
            $role->permissions = array_values(array_unique($data['permissions']));
        }
        $role->save();

        $this->audit->log('role.updated', $role, ['before' => $before, 'after' => $role->only(['name', 'permissions'])]);

        return response()->json(['role' => $role]);
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        if ($role->is_admin || $role->slug === 'admin' || $role->is_system && $role->slug === 'admin') {
            throw ValidationException::withMessages(['role' => "The built-in admin role can't be deleted or stripped."]);
        }
        if ($role->users()->count() > 0) {
            throw ValidationException::withMessages(['role' => 'Cannot delete a role assigned to users.']);
        }

        $this->audit->log('role.deleted', $role, ['name' => $role->name]);
        $role->delete();

        return response()->json(['ok' => true]);
    }
}
