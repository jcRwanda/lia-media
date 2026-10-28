<?php

namespace Tests\Feature;

use App\Mail\MaterialRequestStatusMail;
use App\Models\ApiClient;
use App\Models\EquipmentItem;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Models\User;
use App\Services\MaterialRequestDecisionService;
use App\Services\MaterialRequestWebhookService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MaterialRequestApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function req(EquipmentItem $item, array $o = []): MaterialRequest
    {
        $mr = MaterialRequest::query()->create(array_merge([
            'requester_type' => 'client', 'full_name' => 'Aline Borrower', 'phone' => '0788123456',
            'email' => 'aline@example.com', 'project_name' => 'Shoot',
            'needed_from' => now()->addDays(2)->toDateString(), 'return_by' => now()->addDays(4)->toDateString(), 'status' => 'submitted',
        ], $o));
        MaterialRequestItem::query()->create([
            'material_request_id' => $mr->id, 'equipment_item_id' => $item->id, 'qty_requested' => 2, 'status' => 'pending',
        ]);

        return $mr->fresh();
    }

    public function test_reject_and_request_changes_require_a_note_and_record_history(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Admin A']);
        $item = EquipmentItem::factory()->create(['total_qty' => 5]);
        $mr = $this->req($item);

        $this->actingAs($admin)->postJson("/api/material-requests/{$mr->id}/reject", [])->assertStatus(422);
        $this->postJson("/api/material-requests/{$mr->id}/request-changes", [])->assertStatus(422);

        $this->postJson("/api/material-requests/{$mr->id}/request-changes", ['note' => 'Shorten the dates'])->assertOk();
        $this->assertSame('changes_requested', $mr->fresh()->status);
        Mail::assertQueued(MaterialRequestStatusMail::class);

        $show = $this->getJson("/api/material-requests/{$mr->id}")->assertOk();
        $show->assertJsonPath('request.status_history.0.to_status', 'changes_requested')
            ->assertJsonPath('request.status_history.0.actor', 'Admin A')
            ->assertJsonPath('request.status_history.0.note', 'Shorten the dates');

        // Not shown to the storekeeper until approved.
        $sk = User::factory()->create(['role' => 'storekeeper']);
        $this->actingAs($sk)->getJson('/api/store/dashboard')->assertOk()->assertDontSee($mr->reference_code);

        $this->actingAs($admin)->postJson("/api/material-requests/{$mr->id}/reject", ['note' => 'No stock'])->assertOk();
        $mr->refresh();
        $this->assertSame('rejected', $mr->status);
        $this->assertSame(['changes_requested', 'rejected'], $mr->statusHistories->pluck('to_status')->all());
    }

    public function test_requester_can_edit_and_resubmit_then_approval_shows_whatsapp(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['role' => 'storekeeper', 'name' => 'Jean Store', 'phone' => '0781 111 222', 'status' => 'active']);
        $item = EquipmentItem::factory()->create(['total_qty' => 5]);
        $mr = $this->req($item);

        $this->getJson("/api/v1/material-requests/status/{$mr->uuid}")->assertOk()->assertJsonPath('request.decision.can_resubmit', false);
        $this->postJson("/api/v1/material-requests/status/{$mr->uuid}/resubmit", [])->assertStatus(422);

        $this->actingAs($admin)->postJson("/api/material-requests/{$mr->id}/request-changes", ['note' => 'Only 1 please'])->assertOk();
        auth()->forgetGuards();

        $this->getJson("/api/v1/material-requests/status/{$mr->uuid}")->assertOk()
            ->assertJsonPath('request.decision.can_resubmit', true)
            ->assertJsonPath('request.review_note', 'Only 1 please');

        $this->postJson("/api/v1/material-requests/status/{$mr->uuid}/resubmit", [
            'needed_from' => now()->addDays(3)->toDateString(), 'return_by' => now()->addDays(4)->toDateString(),
            'items' => [['equipment_item_id' => $item->id, 'qty' => 1]], 'note' => 'Done',
        ])->assertOk()->assertJsonPath('request.status', 'submitted');
        $mr->refresh();
        $this->assertSame(1, $mr->items()->first()->qty_requested);

        $this->actingAs($admin)->postJson("/api/material-requests/{$mr->id}/approve", [])->assertOk();
        $d = app(MaterialRequestDecisionService::class)->decision($mr->fresh());
        $this->assertSame('Jean Store', $d['storekeeper']['name']);
        $this->assertStringStartsWith('https://wa.me/250781111222?text=', $d['storekeeper']['whatsapp_url']);
        $this->assertStringContainsString(rawurlencode($mr->reference_code), $d['storekeeper']['whatsapp_url']);

        $this->get('/request-status/'.$mr->uuid)->assertOk()->assertSee('Request status', false);
    }

    public function test_webhook_includes_decision_fields_and_setting_overrides_contact(): void
    {
        Http::fake();
        $client = ApiClient::query()->create(['name' => 'Portal', 'key_hash' => Hash::make('k'), 'prefill_secret' => 's', 'active' => true, 'webhook_url' => 'https://portal.example.com/hook']);
        $item = EquipmentItem::factory()->create(['total_qty' => 5]);
        $mr = $this->req($item, ['api_client_id' => $client->id, 'status' => 'approved', 'review_note' => 'Enjoy']);
        app(SettingsService::class)->put(['storekeeper_whatsapp' => '+250 722 000 111', 'storekeeper_contact_name' => 'Store desk']);

        app(MaterialRequestWebhookService::class)->send($mr, 'submitted');

        Http::assertSent(function ($r) {
            $d = $r->data();

            return $d['decision']['status'] === 'approved'
                && $d['decision']['note'] === 'Enjoy'
                && $d['decision']['storekeeper']['name'] === 'Store desk'
                && str_starts_with($d['decision']['storekeeper']['whatsapp_url'], 'https://wa.me/250722000111?text=')
                && str_contains($d['status_url'], '/request-status/');
        });
    }
}
