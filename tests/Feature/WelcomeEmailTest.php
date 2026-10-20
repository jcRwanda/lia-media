<?php

namespace Tests\Feature;

use App\Mail\WelcomeMail;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WelcomeEmailTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->role(Roles::ADMIN)->create();
    }

    public function test_creating_a_staff_user_generates_a_strong_temp_password_and_sends_the_welcome_email(): void
    {
        Mail::fake();
        $admin = $this->admin();

        $response = $this->actingAs($admin)->postJson('/api/users', [
            'name' => 'Ada Lovelace',
            'email' => 'Ada@Example.com',
            'role' => Roles::MULTIMEDIA,
        ]);

        $response->assertCreated()
            ->assertJsonPath('welcome_email_sent', true)
            ->assertJsonPath('user.must_change_password', true)
            ->assertJsonPath('user.first_login_completed', false);

        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();
        $this->assertTrue($user->must_change_password);

        $captured = null;
        Mail::assertSent(WelcomeMail::class, function (WelcomeMail $mail) use (&$captured) {
            $captured = $mail;

            return $mail->hasTo('ada@example.com');
        });
        $this->assertNotNull($captured);
        $this->assertSame($user->id, $captured->user->id);
        $this->assertSame(20, strlen($captured->temporaryPassword));
        $this->assertTrue(Hash::check($captured->temporaryPassword, $user->password));

        // The plaintext password never reaches the API response or the audit log.
        $this->assertStringNotContainsString($captured->temporaryPassword, $response->getContent());
        $audit = AuditLog::query()->where('action', 'user.created')->firstOrFail();
        $this->assertStringNotContainsString($captured->temporaryPassword, (string) json_encode($audit->properties));
    }

    public function test_welcome_email_content_lists_credentials_and_forces_a_password_change(): void
    {
        $user = User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@example.com']);
        $mail = new WelcomeMail($user, 'Temp-Pass-9384xyz', url('/login'));

        $mail->assertHasSubject('Welcome to LIA Media Archive');
        $mail->assertSeeInHtml('Grace Hopper');
        $mail->assertSeeInHtml('grace@example.com');
        $mail->assertSeeInHtml('Temp-Pass-9384xyz');
        $mail->assertSeeInHtml(url('/login'));
        $mail->assertSeeInHtml('must change this temporary password the first time you sign in');
        $mail->assertSeeInText('Temp-Pass-9384xyz');
        $mail->assertSeeInText(url('/login'));
    }

    public function test_welcome_email_is_queued_when_queue_connection_is_not_sync(): void
    {
        config(['queue.default' => 'database']);
        Mail::fake();

        $this->actingAs($this->admin())->postJson('/api/users', [
            'name' => 'Queued Staff',
            'email' => 'queued@example.com',
            'role' => Roles::MANAGEMENT,
        ])->assertCreated()->assertJsonPath('welcome_email_sent', true);

        Mail::assertQueued(WelcomeMail::class);
        Mail::assertNotSent(WelcomeMail::class);
    }

    public function test_user_creation_succeeds_when_the_welcome_email_fails(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);

        $response = $this->actingAs($this->admin())->postJson('/api/users', [
            'name' => 'Unlucky Staff',
            'email' => 'unlucky@example.com',
            'role' => Roles::MULTIMEDIA,
        ]);

        $response->assertCreated()->assertJsonPath('welcome_email_sent', false);
        $user = User::query()->where('email', 'unlucky@example.com')->firstOrFail();
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(AuditLog::query()->where('action', 'user.created')->where('subject_id', $user->id)->exists());
    }

    public function test_resend_welcome_rotates_the_password_relocks_and_resends(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $user = User::factory()->create(['email' => 'staff@example.com']);

        $this->actingAs($admin)->postJson("/api/users/{$user->id}/resend-welcome")
            ->assertOk()
            ->assertJsonPath('welcome_email_sent', true)
            ->assertJsonPath('user.must_change_password', true)
            ->assertJsonPath('user.first_login_completed', false);

        $user->refresh();
        $this->assertTrue($user->must_change_password);
        $this->assertFalse(Hash::check('password', $user->password));

        $captured = null;
        Mail::assertSent(WelcomeMail::class, function (WelcomeMail $mail) use (&$captured) {
            $captured = $mail;

            return $mail->hasTo('staff@example.com');
        });
        $this->assertNotNull($captured);
        $this->assertTrue(Hash::check($captured->temporaryPassword, $user->password));
        $this->assertTrue(AuditLog::query()->where('action', 'user.welcome_resent')->where('subject_id', $user->id)->exists());
    }

    public function test_resend_welcome_requires_users_manage_ability(): void
    {
        $user = User::factory()->create();
        $mm = User::factory()->role(Roles::MULTIMEDIA)->create();

        $this->actingAs($mm)->postJson("/api/users/{$user->id}/resend-welcome")->assertForbidden();

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('password', $user->password));
    }

    public function test_users_list_reports_whether_first_login_is_complete(): void
    {
        $pending = User::factory()->create(['must_change_password' => true]);
        $completed = User::factory()->create(['last_login_at' => now()]);
        $neverLoggedIn = User::factory()->create();

        $data = collect($this->actingAs($this->admin())->getJson('/api/users')->assertOk()->json('data'))->keyBy('id');

        $this->assertTrue($data[$pending->id]['must_change_password']);
        $this->assertFalse($data[$pending->id]['first_login_completed']);
        $this->assertFalse($data[$completed->id]['must_change_password']);
        $this->assertTrue($data[$completed->id]['first_login_completed']);
        $this->assertFalse($data[$neverLoggedIn->id]['first_login_completed']);
        $this->assertArrayNotHasKey('password', $data[$pending->id]);
    }
}
