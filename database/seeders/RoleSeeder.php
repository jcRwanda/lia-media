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
                'permissions' => ['library.view', 'assets.upload', 'assets.edit', 'albums.manage', 'shares.manage', 'dashboard.view'],
            ]
        );

        $communications = Role::updateOrCreate(
            ['slug' => Roles::COMMUNICATIONS],
            [
                'name' => 'Communications',
                'is_system' => true,
                'is_admin' => false,
                'permissions' => ['library.view', 'assets.edit', 'assets.download_original', 'albums.manage', 'shares.manage', 'review.approve', 'review.publish', 'consent.manage', 'publications.manage', 'dashboard.view', 'material-requests.view', 'material-requests.manage', 'material-requests.issue', 'material-requests.return', 'equipment.manage'],
            ]
        );

        $management = Role::updateOrCreate(
            ['slug' => Roles::MANAGEMENT],
            [
                'name' => 'Management',
                'is_system' => true,
                'is_admin' => false,
                'permissions' => ['library.view', 'assets.download_original', 'dashboard.view', 'audit.view', 'material-requests.view', 'material-requests.manage', 'material-requests.issue', 'material-requests.return', 'equipment.manage'],
            ]
        );

        $storekeeper = Role::updateOrCreate(
            ['slug' => Roles::STOREKEEPER],
            [
                'name' => 'Storekeeper',
                'is_system' => true,
                'is_admin' => false,
                'permissions' => ['material-requests.view', 'material-requests.issue', 'material-requests.return', 'equipment.manage'],
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
