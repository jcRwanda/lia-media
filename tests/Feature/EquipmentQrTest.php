<?php

namespace Tests\Feature;

use App\Models\EquipmentCheck;
use App\Models\EquipmentItem;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EquipmentQrTest extends TestCase
{
    use RefreshDatabase;

    private function booking(EquipmentItem $item, array $o = []): MaterialRequest
    {
        $mr = MaterialRequest::query()->create(array_merge([
            'requester_type' => 'client', 'full_name' => 'Aline Borrower', 'phone' => '+250 788 123 456',
            'email' => 'Aline@Example.com', 'project_name' => 'Shoot',
            'needed_from' => now()->toDateString(), 'return_by' => now()->addDays(2)->toDateString(), 'status' => 'approved',
        ], $o));
        MaterialRequestItem::query()->create([
            'material_request_id' => $mr->id, 'equipment_item_id' => $item->id,
            'qty_requested' => 1, 'qty_approved' => 1, 'status' => 'approved',
        ]);

        return $mr;
    }

    public function test_items_get_unique_qr_tokens(): void
    {
        $a = EquipmentItem::factory()->create();
        $b = EquipmentItem::factory()->create();
        $this->assertSame(32, strlen($a->qr_token));
        $this->assertNotSame($a->qr_token, $b->qr_token);
    }

    public function test_storekeeper_cannot_change_serial_or_delete_but_can_edit_name(): void
    {
        $sk = User::factory()->create(['role' => 'storekeeper']);
        $item = EquipmentItem::factory()->create(['serial_number' => 'SN-1']);
        $h = ['Accept' => 'application/json'];

        $this->actingAs($sk)->post('/api/equipment-items', ['name' => 'Cam', 'serial_number' => 'NEW-9', 'total_qty' => 1], $h)
            ->assertCreated()->assertJsonPath('item.serial_number', 'NEW-9')->assertJsonPath('item.can_edit_serial', false);
        $this->actingAs($sk)->patch("/api/equipment-items/{$item->id}", ['name' => 'Renamed', 'serial_number' => 'SN-1'], $h)->assertOk();
        $this->assertSame('Renamed', $item->fresh()->name);
        $this->actingAs($sk)->patch("/api/equipment-items/{$item->id}", ['serial_number' => 'HACK'], $h)->assertForbidden();
        $this->assertSame('SN-1', $item->fresh()->serial_number);
        $this->actingAs($sk)->delete("/api/equipment-items/{$item->id}", [], $h)->assertForbidden();
        $this->assertNotNull($item->fresh());
    }

    public function test_admin_has_full_crud(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $item = EquipmentItem::factory()->create(['serial_number' => 'SN-1']);
        $h = ['Accept' => 'application/json'];
        $this->actingAs($admin)->patch("/api/equipment-items/{$item->id}", ['serial_number' => 'SN-2'], $h)->assertOk();
        $this->assertSame('SN-2', $item->fresh()->serial_number);
        $this->actingAs($admin)->delete("/api/equipment-items/{$item->id}", [], $h)->assertOk();
        $this->assertSoftDeleted($item);
    }

    public function test_public_token_resolution(): void
    {
        $item = EquipmentItem::factory()->create(['name' => 'Sony A7', 'serial_number' => 'ABC123456']);
        $this->getJson("/api/v1/qr/{$item->qr_token}")->assertOk()
            ->assertJsonPath('item.name', 'Sony A7')->assertJsonPath('item.serial_hint', '•••••3456');
        $this->getJson('/api/v1/qr/'.str_repeat('x', 32))->assertNotFound();
    }

    public function test_borrower_confirms_with_phone_or_email_only_when_matching(): void
    {
        $item = EquipmentItem::factory()->create();
        $mr = $this->booking($item);

        $this->postJson("/api/v1/qr/{$item->qr_token}/confirm", ['contact' => '0700000000'])->assertStatus(422);
        $this->postJson("/api/v1/qr/{$item->qr_token}/confirm", ['contact' => '0788123456'])->assertOk()
            ->assertJsonPath('booking.reference_code', $mr->reference_code);
        $this->postJson("/api/v1/qr/{$item->qr_token}/confirm", ['contact' => 'aline@example.com'])->assertOk();

        $this->assertSame(1, EquipmentCheck::query()->where('type', 'pickup_confirm')->count());
        $check = EquipmentCheck::query()->first();
        $this->assertSame('phone', $check->verified_via);
        $this->assertNotNull($check->ip);
        $this->assertDatabaseHas('material_request_audits', ['material_request_id' => $mr->id, 'action' => 'equipment.pickup_confirmed']);
    }

    public function test_borrower_cannot_confirm_unapproved_or_past_booking(): void
    {
        $item = EquipmentItem::factory()->create();
        $this->booking($item, ['status' => 'submitted']);
        $this->booking($item, ['needed_from' => now()->subDays(10)->toDateString(), 'return_by' => now()->subDays(5)->toDateString()]);
        $this->postJson("/api/v1/qr/{$item->qr_token}/confirm", ['contact' => '0788123456'])->assertStatus(422);
    }

    public function test_storekeeper_scan_and_checks_with_flags(): void
    {
        $sk = User::factory()->create(['role' => 'storekeeper']);
        $item = EquipmentItem::factory()->create(['serial_number' => 'SN-77']);
        $other = EquipmentItem::factory()->create();
        $mr = $this->booking($item);
        $otherMr = $this->booking($other);

        $this->actingAs($sk)->getJson("/api/equipment-scan/{$item->qr_token}")->assertOk()
            ->assertJsonPath('item.serial_number', 'SN-77')->assertJsonPath('bookings.0.id', $mr->id);

        $this->actingAs($sk)->postJson("/api/equipment-scan/{$item->qr_token}/check", [
            'type' => 'issue_check', 'material_request_id' => $mr->id, 'serial_matches' => true,
        ])->assertCreated()->assertJsonPath('check.flagged', false);

        $this->actingAs($sk)->postJson("/api/equipment-scan/{$item->qr_token}/check", [
            'type' => 'return_check', 'material_request_id' => $mr->id, 'serial_matches' => false, 'notes' => 'scratched',
        ])->assertCreated()->assertJsonPath('check.flagged', true);

        $this->actingAs($sk)->postJson("/api/equipment-scan/{$item->qr_token}/check", [
            'type' => 'issue_check', 'material_request_id' => $otherMr->id, 'serial_matches' => true,
        ])->assertCreated()->assertJsonPath('check.flag_reason', 'Item is not on this booking');

        $this->assertDatabaseHas('material_request_audits', ['material_request_id' => $mr->id, 'action' => 'equipment.return_check']);

        $this->actingAs($sk)->getJson("/api/material-requests/{$mr->id}")->assertOk()->assertJsonCount(2, 'request.items.0.checks');
    }

    public function test_scan_requires_staff(): void
    {
        $item = EquipmentItem::factory()->create();
        $this->getJson("/api/equipment-scan/{$item->qr_token}")->assertUnauthorized();
        $u = User::factory()->create(['role' => 'multimedia']);
        $this->actingAs($u)->getJson("/api/equipment-scan/{$item->qr_token}")->assertForbidden();
    }
}
