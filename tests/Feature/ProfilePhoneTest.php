<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MaterialRequestDecisionService;
use App\Support\Phone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfilePhoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalizes_rwandan_numbers(): void
    {
        $this->assertSame('+250788123456', Phone::normalize('0788 123 456'));
        $this->assertSame('+250788123456', Phone::normalize('788123456'));
        $this->assertSame('+250788123456', Phone::normalize('250788123456'));
        $this->assertSame('+250788123456', Phone::normalize('+250 788-123-456'));
        $this->assertSame('+250788123456', Phone::normalize('00250788123456'));
        $this->assertSame('+14155550123', Phone::normalize('+1 415 555 0123'));
        $this->assertNull(Phone::normalize('12345'));
        $this->assertNull(Phone::normalize('0788abc456'));
        $this->assertNull(Phone::normalize('+25078812345'));
    }

    public function test_storekeeper_sets_own_whatsapp_number_used_for_approval_link(): void
    {
        $sk = User::factory()->create(['role' => 'storekeeper', 'status' => 'active', 'phone' => null]);

        $this->actingAs($sk)->patchJson('/api/auth/me', ['phone' => '0788123456'])
            ->assertOk()->assertJsonPath('user.phone', '+250788123456');

        $contact = app(MaterialRequestDecisionService::class)->storekeeperContact();
        $this->assertSame('250788123456', $contact['whatsapp']);
    }

    public function test_invalid_phone_is_rejected_and_blank_clears(): void
    {
        $u = User::factory()->create(['role' => 'communications', 'phone' => '+250788123456']);
        $this->actingAs($u)->patchJson('/api/auth/me', ['phone' => '12ab'])->assertStatus(422)->assertJsonValidationErrors(['phone']);
        $this->actingAs($u)->patchJson('/api/auth/me', ['phone' => ''])->assertOk();
        $this->assertNull($u->fresh()->phone);
    }
}
