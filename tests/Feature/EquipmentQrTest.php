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

    public function test_confirm_explains_different_item_and_not_approved(): void
    {
        $listed = EquipmentItem::factory()->create(['name' => 'Canon R body']);
        $scanned = EquipmentItem::factory()->create(['name' => 'Canon charge R', 'serial_number' => 'KEHB']);
        $this->booking($listed, ['email' => 'assoumpta255@gmail.com', 'phone' => '+250787739837']);

        $res = $this->postJson("/api/v1/qr/{$scanned->qr_token}/confirm", ['contact' => 'assoumpta255@gmail.com'])
            ->assertStatus(422);
        $this->assertSame('different_item', $res->json('reason'));
        $this->assertStringContainsString('not on that list', $res->json('message'));
        $this->assertStringContainsString('storekeeper', $res->json('message'));

        $pendingItem = EquipmentItem::factory()->create();
        $this->booking($pendingItem, [
            'email' => 'waiting@example.com',
            'status' => 'submitted',
            'phone' => '0788111222',
        ]);
        $this->postJson("/api/v1/qr/{$pendingItem->qr_token}/confirm", ['contact' => 'waiting@example.com'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'not_approved');
    }

    public function test_canon_substitution_leaves_the_booking_unchanged(): void
    {
        $listed = EquipmentItem::factory()->create(['name' => 'Canon R body', 'serial_number' => 'BODY-1']);
        $scanned = EquipmentItem::factory()->create(['name' => 'Canon charge R', 'serial_number' => 'KEHB']);
        $mr = $this->booking($listed, ['email' => 'borrower@example.com', 'phone' => '0788123456']);
        $line = $mr->items()->firstOrFail();

        $this->postJson("/api/v1/qr/{$listed->qr_token}/confirm", ['contact' => 'borrower@example.com'])
            ->assertOk()
            ->assertJsonPath('booking.reference_code', $mr->reference_code);

        $this->postJson("/api/v1/qr/{$scanned->qr_token}/confirm", ['contact' => 'borrower@example.com'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'different_item');

        $this->assertSame($listed->id, $line->fresh()->equipment_item_id);
        $this->assertDatabaseMissing('equipment_checks', [
            'material_request_id' => $mr->id,
            'equipment_item_id' => $scanned->id,
        ]);
        $this->assertDatabaseMissing('material_request_audits', [
            'material_request_id' => $mr->id,
            'action' => 'equipment.pickup_item_remapped',
        ]);
    }

    public function test_deleted_unit_is_not_remapped_until_storekeeper_replaces_it(): void
    {
        $standIn = EquipmentItem::factory()->create(['name' => 'Camera battery chargers']);
        $scanned = EquipmentItem::factory()->create(['name' => 'Canon charge R', 'serial_number' => 'KEHB', 'total_qty' => 2, 'condition' => 'good']);
        $mr = $this->booking($standIn, ['email' => 'borrower@example.com', 'phone' => '0788123456']);
        $line = $mr->items()->firstOrFail();
        $standIn->delete();

        $this->postJson("/api/v1/qr/{$scanned->qr_token}/confirm", ['contact' => 'borrower@example.com'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'replacement_required')
            ->assertJsonPath('errors.contact.0', 'Contact the storekeeper. They must replace the booked unit explicitly.');

        $this->assertSame($standIn->id, $line->fresh()->equipment_item_id);
        $this->assertSame(0, EquipmentCheck::query()->count());

        $this->postJson("/api/material-requests/{$mr->id}/items/{$line->id}/replace", [
            'equipment_item_id' => $scanned->id,
        ])->assertUnauthorized();

        $storekeeper = User::factory()->create(['role' => 'storekeeper', 'name' => 'Sam Store']);
        $this->actingAs($storekeeper)->postJson("/api/material-requests/{$mr->id}/items/{$line->id}/replace", [
            'equipment_item_id' => $scanned->id,
        ])->assertOk()->assertJsonPath('item.equipment_item_id', $scanned->id);

        $this->assertSame($scanned->id, $line->fresh()->equipment_item_id);
        $this->assertSame(0, EquipmentCheck::query()->where('type', 'pickup_confirm')->count());
        $this->assertDatabaseHas('material_request_audits', [
            'material_request_id' => $mr->id,
            'action' => 'equipment.pickup_item_replaced',
        ]);

        $this->postJson("/api/v1/qr/{$scanned->qr_token}/confirm", ['contact' => 'borrower@example.com'])
            ->assertOk()
            ->assertJsonPath('booking.reference_code', $mr->reference_code);
        $this->assertDatabaseHas('equipment_checks', [
            'material_request_id' => $mr->id,
            'equipment_item_id' => $scanned->id,
            'type' => 'pickup_confirm',
        ]);
    }
}
