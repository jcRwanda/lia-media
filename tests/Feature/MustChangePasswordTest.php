<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MustChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    private function spa(): static
    {
        return $this->withHeaders(['Origin' => 'http://localhost', 'Referer' => 'http://localhost/', 'Accept' => 'application/json']);
    }

    private function pendingUser(array $attributes = []): User
    {
        return User::factory()->create([
            'email' => 'newstaff@example.com',
            'password' => 'Temp-Pass-9384xyz',
            'must_change_password' => true,
            ...$attributes,
        ]);
    }

    public function test_login_reports_the_flag_and_other_routes_stay_locked_until_the_password_changes(): void
    {
        $admin = $this->pendingUser(['role' => Roles::ADMIN, 'email' => 'pending@example.com']);

        $this->spa()->postJson('/api/auth/login', ['email' => 'pending@example.com', 'password' => 'Temp-Pass-9384xyz'])
            ->assertOk()
            ->assertJsonPath('user.must_change_password', true);

        // me stays reachable so the SPA can send the user to the set-password page.
        $this->spa()->getJson('/api/auth/me')->assertOk()
            ->assertJsonPath('user.must_change_password', true)
            ->assertJsonPath('user.first_login_completed', false);

        // Everything else is blocked — even for an admin whose role would allow it.
        foreach (['/api/lookups', '/api/dashboard', '/api/users', '/api/audit-logs'] as $path) {
            $this->spa()->getJson($path)->assertForbidden()
                ->assertJsonPath('message', 'You must set a new password before continuing.');
        }
        $this->spa()->postJson('/api/users', ['name' => 'X', 'email' => 'x@example.com', 'role' => Roles::MULTIMEDIA])
            ->assertForbidden();

        // Signing out stays possible.
        $this->spa()->postJson('/api/auth/logout')->assertOk();
    }

    public function test_change_password_enforces_the_rules_clears_the_flag_and_unlocks_the_api(): void
    {
        $user = $this->pendingUser();
        $this->spa()->postJson('/api/auth/login', ['email' => 'newstaff@example.com', 'password' => 'Temp-Pass-9384xyz'])->assertOk();
        // The guard caches the user instance from attempt(), before last_login_at was written — reload it.
        $this->app['auth']->forgetGuards();

        $this->spa()->postJson('/api/auth/change-password', ['password' => 'short12', 'password_confirmation' => 'short12'])
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'The password field must be at least 10 characters.');

        $this->spa()->postJson('/api/auth/change-password', ['password' => 'brand-new-pass-1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->spa()->postJson('/api/auth/change-password', ['password' => 'Temp-Pass-9384xyz', 'password_confirmation' => 'Temp-Pass-9384xyz'])
            ->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'The new password must be different from your current password.');

        // Still locked after the failed attempts.
        $this->spa()->getJson('/api/lookups')->assertForbidden();

        $this->spa()->postJson('/api/auth/change-password', ['password' => 'my-own-Password1', 'password_confirmation' => 'my-own-Password1'])
            ->assertOk()
            ->assertJsonPath('user.must_change_password', false)
            ->assertJsonPath('user.first_login_completed', true);

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('my-own-Password1', $user->password));
        $this->assertFalse(Hash::check('Temp-Pass-9384xyz', $user->password));
        $this->assertTrue(AuditLog::query()->where('action', 'auth.password_changed')->where('subject_id', $user->id)->exists());

        $this->spa()->getJson('/api/lookups')->assertOk();

        // The new password works for a fresh login and the flag stays cleared.
        $this->spa()->postJson('/api/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->spa()->postJson('/api/auth/login', ['email' => 'newstaff@example.com', 'password' => 'my-own-Password1'])
            ->assertOk()
            ->assertJsonPath('user.must_change_password', false)
            ->assertJsonPath('user.first_login_completed', true);
    }

    public function test_change_password_requires_a_session(): void
    {
        $this->spa()->postJson('/api/auth/change-password', ['password' => 'brand-new-pass-1', 'password_confirmation' => 'brand-new-pass-1'])
            ->assertUnauthorized();
    }
}
