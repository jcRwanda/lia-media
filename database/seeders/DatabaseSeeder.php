<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Database\Seeder;

/** LOCAL DEVELOPMENT ONLY: one account per role, password "password". Never run in production. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RoleSeeder::class);

        if (app()->environment('production')) {
            $this->command?->error('Refusing to seed demo users in production.');

            return;
        }
        foreach (Roles::ALL as $role) {
            User::query()->updateOrCreate(['email' => "{$role}@media.test"], ['name' => ucfirst($role).' Demo', 'password' => 'password', 'role' => $role, 'status' => 'active']);
        }
        $this->call(DemoContentSeeder::class);
    }
}

// Add role seeding
