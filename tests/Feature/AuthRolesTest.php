<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthRolesTest extends TestCase
{
    use RefreshDatabase;

    private function spa(): static
    {
        return $this->withHeaders(['Origin' => 'http://localhost', 'Referer' => 'http://localhost/', 'Accept' => 'application/json']);
    }

    public function test_login_me_logout_with_spa_session_and_audit(): void
    {
        $u = User::factory()->role(Roles::COMMUNICATIONS)->create(['email' => 'comms@example.com']);
        $this->spa()->postJson('/api/auth/login', ['email' => 'COMMS@example.com', 'password' => 'password'])
            ->assertOk()->assertJsonPath('user.role', 'communications')
            ->assertJsonPath('user.abilities', fn ($a) => in_array('review.publish', $a) && ! in_array('users.manage', $a));
        $this->spa()->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.email', 'comms@example.com');
        $this->assertNotNull($u->fresh()->last_login_at);
        $this->assertTrue(AuditLog::where('action', 'auth.login')->where('actor_id', $u->id)->exists());
        $this->spa()->postJson('/api/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->spa()->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_bad_password_and_disabled_accounts_are_refused_and_logged(): void
    {
        User::factory()->create(['email' => 'a@example.com']);
        User::factory()->create(['email' => 'off@example.com', 'status' => 'disabled']);
        $this->spa()->postJson('/api/auth/login', ['email' => 'a@example.com', 'password' => 'nope'])->assertStatus(422);
        $this->spa()->postJson('/api/auth/login', ['email' => 'off@example.com', 'password' => 'password'])->assertStatus(422);
        $this->assertSame(2, AuditLog::where('action', 'auth.login_failed')->count());
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'a@example.com']);
        foreach (range(1, 5) as $i) {
            $this->spa()->postJson('/api/auth/login', ['email' => 'a@example.com', 'password' => 'x'])->assertStatus(422);
        }
        $this->spa()->postJson('/api/auth/login', ['email' => 'a@example.com', 'password' => 'password'])->assertStatus(429);
    }

    public function test_role_matrix(): void
    {
        $this->assertTrue(Roles::allows('admin', 'users.manage'));
        $this->assertTrue(Roles::allows('multimedia', 'assets.upload'));
        $this->assertFalse(Roles::allows('multimedia', 'review.publish'));
        $this->assertTrue(Roles::allows('communications', 'review.publish'));
        $this->assertFalse(Roles::allows('communications', 'assets.upload'));
        $this->assertTrue(Roles::allows('management', 'audit.view'));
        $this->assertFalse(Roles::allows('management', 'assets.edit'));
        $this->assertFalse(Roles::allows('multimedia', 'taxonomy.manage'));
        $this->assertFalse(Roles::allows('communications', 'taxonomy.manage'));
        $this->assertTrue(Roles::allows('admin', 'taxonomy.manage'));
        $this->assertFalse(Roles::allows(null, 'library.view'));
    }

    public function test_only_admin_manages_users_and_cannot_demote_self(): void
    {
        $admin = User::factory()->role('admin')->create();
        $mm = User::factory()->role('multimedia')->create();
        $this->actingAs($mm)->getJson('/api/users')->assertForbidden();
        $this->actingAs($admin)->postJson('/api/users', ['name' => 'New', 'email' => 'new@example.com', 'role' => 'management'])
            ->assertCreated()->assertJsonPath('user.role', 'management');
        $this->actingAs($admin)->patchJson("/api/users/{$admin->id}", ['role' => 'multimedia'])->assertStatus(422);
        $this->actingAs($admin)->patchJson("/api/users/{$mm->id}", ['status' => 'disabled'])->assertOk();
        $this->actingAs($mm->fresh())->getJson('/api/auth/me')->assertForbidden();
        $this->assertTrue(AuditLog::where('action', 'user.updated')->exists());
    }

    public function test_audit_log_visible_to_management_not_multimedia(): void
    {
        $this->actingAs(User::factory()->role('management')->create())->getJson('/api/audit-logs')->assertOk();
        $this->actingAs(User::factory()->role('multimedia')->create())->getJson('/api/audit-logs')->assertForbidden();
    }

    public function test_spa_shell_served_for_app_routes(): void
    {
        $this->withoutVite()->get('/library')->assertOk()->assertSee('id="root"', false);
    }
}
