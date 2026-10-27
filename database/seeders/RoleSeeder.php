<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // Create built-in roles with permissions
        $admin = Role::updateOrCreate(
            ['slug' => Roles::ADMIN],
            [
                'name' => 'Administrator',
                'is_system' => true,
                'is_admin' => true,
                'permissions' => [],
            ]
        );

        $multimedia = Role::updateOrCreate(
            ['slug' => Roles::MULTIMEDIA],
            [
                'name' => 'Multimedia',
                'is_system' => true,
                'is_admin' => false,
                'permissions' => Roles::abilitiesFor(Roles::MULTIMEDIA),
            ]
        );

        $communications = Role::updateOrCreate(
            ['slug' => Roles::COMMUNICATIONS],
            [
                'name' => 'Communications',
                'is_system' => true,
                'is_admin' => false,
                'permissions' => Roles::abilitiesFor(Roles::COMMUNICATIONS),
            ]
        );

        $management = Role::updateOrCreate(
            ['slug' => Roles::MANAGEMENT],
            [
                'name' => 'Management',
                'is_system' => true,
                'is_admin' => false,
                'permissions' => Roles::abilitiesFor(Roles::MANAGEMENT),
            ]
        );

        $storekeeper = Role::updateOrCreate(
            ['slug' => Roles::STOREKEEPER],
            [
                'name' => 'Storekeeper',
                'is_system' => true,
                'is_admin' => false,
                'permissions' => Roles::abilitiesFor(Roles::STOREKEEPER),
            ]
        );

        // Migrate existing users to link to roles
        foreach (User::all() as $user) {
            $slug = $user->role;
            $role = Role::where('slug', $slug)->first();
            if ($role) {
                $user->role_id = $role->id;
                $user->save();
            }
        }
    }
}
