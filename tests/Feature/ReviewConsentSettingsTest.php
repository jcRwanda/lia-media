<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Person;
use App\Models\ReviewEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesFakeStorage;
use Tests\TestCase;

class ReviewConsentSettingsTest extends TestCase
{
    use RefreshDatabase, UsesFakeStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakeStorage();
    }

    public function test_review_workflow_transitions_and_role_limits(): void
    {
        $comms = User::factory()->role('communications')->create();
        $mgmt = User::factory()->role('management')->create();
        $mm = User::factory()->role('multimedia')->create();
        $a = Asset::factory()->create(['review_state' => 'unreviewed']);

        $this->actingAs($mm)->postJson("/api/assets/{$a->uuid}/review", ['to_state' => 'approved'])->assertForbidden();
        $this->actingAs($mgmt)->postJson("/api/assets/{$a->uuid}/review", ['to_state' => 'published'])->assertStatus(422); // not allowed from unreviewed
        $this->actingAs($mgmt)->postJson("/api/assets/{$a->uuid}/review", ['to_state' => 'approved'])->assertOk()->assertJsonPath('asset.review_state', 'approved');
        $this->actingAs($mgmt)->postJson("/api/assets/{$a->uuid}/review", ['to_state' => 'published'])->assertStatus(403); // management can't publish
        $this->actingAs($comms)->postJson("/api/assets/{$a->uuid}/review", ['to_state' => 'restricted'])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->actingAs($comms)->postJson("/api/assets/{$a->uuid}/review", ['to_state' => 'published'])->assertOk()->assertJsonPath('asset.review_state', 'published')
            ->assertJsonCount(2, 'asset.review_history');
        $this->assertSame(2, ReviewEvent::query()->where('asset_id', $a->id)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'asset.review']);

        $b = Asset::factory()->create(['review_state' => 'unreviewed']);
        $this->actingAs($comms)->postJson('/api/review/bulk', ['uuids' => [$a->uuid, $b->uuid], 'to_state' => 'approved'])
            ->assertOk()->assertJsonPath('updated', 2)->assertJsonCount(0, 'failed');
    }

    public function test_publish_blocked_without_consent_and_withdrawal_restricts(): void
    {
        $comms = User::factory()->role('communications')->create();
        $a = Asset::factory()->create(['review_state' => 'approved']);
        $person = Person::query()->create(['full_name' => 'Test Person', 'is_minor' => true]);

        $this->actingAs($comms)->postJson("/api/assets/{$a->uuid}/people", ['person_id' => $person->id])->assertOk()->assertJsonPath('asset.consent_status', 'missing');
        $this->actingAs($comms)->postJson("/api/assets/{$a->uuid}/review", ['to_state' => 'published'])->assertStatus(422);

        $this->actingAs($comms)->postJson("/api/people/{$person->id}/consents", ['scope' => 'photo', 'signed_on' => now()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('guardian_name'); // minors need a guardian
        $cid = $this->actingAs($comms)->postJson("/api/people/{$person->id}/consents", ['scope' => 'photo', 'signed_on' => now()->toDateString(), 'guardian_name' => 'Guardian'])
            ->assertCreated()->json('data.id');
        $this->assertSame('covered', $a->fresh()->consent_status);
        $this->actingAs($comms)->postJson("/api/assets/{$a->uuid}/review", ['to_state' => 'published'])->assertOk();
        $this->actingAs($comms)->postJson("/api/assets/{$a->uuid}/publications", ['channel' => 'instagram', 'url' => 'https://instagram.com/p/x', 'published_on' => now()->toDateString()])
            ->assertCreated()->assertJsonCount(1, 'asset.publications');

        $this->actingAs($comms)->postJson("/api/consents/{$cid}/withdraw", ['reason' => 'Family request'])->assertOk()->assertJsonPath('assets_restricted', 1);
        $a->refresh();
        $this->assertSame('restricted', $a->review_state);
        $this->assertSame('withdrawn', $a->consent_status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'consent.withdrawn']);
    }

    public function test_dashboard_storage_and_settings(): void
    {
        $admin = User::factory()->role('admin')->create();
        $mgmt = User::factory()->role('management')->create();
        Asset::factory()->create(['size_bytes' => 5 * 1024 ** 3]);
        Asset::factory()->create(['size_bytes' => 10 * 1024 ** 3, 'review_state' => 'approved']);

        $this->actingAs($mgmt)->getJson('/api/dashboard')->assertOk()->assertJsonPath('review_counts.approved', 1)->assertJsonPath('storage.asset_count', 2);
        // 15 GB − 10 GB free × $0.015
        $this->actingAs($mgmt)->getJson('/api/storage')->assertOk()->assertJsonPath('estimated_monthly_usd', 0.08);
        $this->actingAs($mgmt)->getJson('/api/settings')->assertForbidden();

        $this->actingAs($admin)->getJson('/api/settings')->assertOk()->assertJsonPath('settings.default_share_days', 30)->assertJsonPath('system.backup_enabled', false);
        $this->actingAs($admin)->patchJson('/api/settings', ['default_share_days' => 7, 'storage_free_gb' => 0])->assertOk()->assertJsonPath('settings.default_share_days', 7);
        $this->actingAs($mgmt)->getJson('/api/storage')->assertJsonPath('estimated_monthly_usd', 0.22); // 15 GB x 0.015 = 0.225, float rounds down
        $this->actingAs($admin)->postJson('/api/storage/snapshot')->assertOk()->assertJsonStructure(['objects', 'bytes']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.updated']);
    }
}
