<?php

namespace Tests\Feature;

use App\Models\EquipmentItem;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MaterialRequestPickupAddTest extends TestCase
{
    use RefreshDatabase;

    private function booking(array $overrides = [], array $itemOverrides = []): MaterialRequest
    {
        $request = MaterialRequest::query()->create(array_merge([
            'requester_type' => 'client',
            'full_name' => 'Test Requester',
            'phone' => '0788123456',
            'email' => 'requester@example.com',
            'project_location' => 'Kigali',
            'needed_from' => now()->toDateString(),
            'return_by' => now()->addDays(3)->toDateString(),
            'status' => 'approved',
        ], $overrides));

        $equipment = EquipmentItem::factory()->create(['total_qty' => 5, 'condition' => 'good', 'name' => 'Main Camera']);

        MaterialRequestItem::query()->create(array_merge([
            'material_request_id' => $request->id,
            'equipment_item_id' => $equipment->id,
            'qty_requested' => 1,
            'qty_approved' => 1,
            'status' => 'approved',
        ], $itemOverrides));

        return $request->fresh(['items.equipmentItem']);
    }

    public function test_storekeeper_can_add_and_remove_item_at_pickup(): void
    {
        $storekeeper = User::factory()->create(['role' => 'storekeeper', 'name' => 'Sam Store']);
        $booking = $this->booking();
        $extra = EquipmentItem::factory()->create(['total_qty' => 4, 'condition' => 'good', 'name' => 'Tripod']);

        $add = $this->actingAs($storekeeper)->postJson("/api/material-requests/{$booking->id}/items", [
            'equipment_item_id' => $extra->id,
            'qty' => 2,
        ])->assertCreated();

        $lineId = $add->json('item.id');
        $this->assertTrue($add->json('item.added_at_pickup'));
        $this->assertSame('Sam Store', $add->json('item.added_by'));
        $this->assertSame(2, $add->json('item.qty_approved'));

        $booking->refresh()->load('statusHistories');
        $this->assertTrue($booking->statusHistories->contains(fn ($h) => $h->to_status === 'pickup_item_added'
            && str_contains((string) $h->note, 'Added at pickup by Sam Store')
            && str_contains((string) $h->note, 'Tripod')));

        $this->actingAs($storekeeper)
            ->getJson("/api/material-requests/{$booking->id}")
            ->assertOk()
            ->assertJsonFragment(['added_at_pickup' => true, 'name' => 'Tripod']);

        $this->actingAs($storekeeper)
            ->deleteJson("/api/material-requests/{$booking->id}/items/{$lineId}")
            ->assertOk();

        $this->assertDatabaseMissing('material_request_items', ['id' => $lineId]);
        $this->assertTrue($booking->fresh()->statusHistories->contains(fn ($h) => $h->to_status === 'pickup_item_removed'));
    }

    public function test_can_add_via_qr_token(): void
    {
        $storekeeper = User::factory()->create(['role' => 'storekeeper']);
        $booking = $this->booking();
        $extra = EquipmentItem::factory()->create(['total_qty' => 2, 'condition' => 'good']);

        $this->actingAs($storekeeper)->postJson("/api/material-requests/{$booking->id}/items", [
            'qr_token' => $extra->qr_token,
            'qty' => 1,
        ])->assertCreated()
            ->assertJsonPath('item.equipment_item_id', $extra->id);
    }

    public function test_rejects_unavailable_duplicate_and_double_booked_items(): void
    {
        $storekeeper = User::factory()->create(['role' => 'storekeeper']);
        $booking = $this->booking();
        $damaged = EquipmentItem::factory()->create(['total_qty' => 3, 'condition' => 'Needs service', 'name' => 'Broken Light']);
        $onRequest = $booking->items->first()->equipmentItem;

        $this->actingAs($storekeeper)->postJson("/api/material-requests/{$booking->id}/items", [
            'equipment_item_id' => $damaged->id,
            'qty' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors(['equipment_item_id']);

        $this->actingAs($storekeeper)->postJson("/api/material-requests/{$booking->id}/items", [
            'equipment_item_id' => $onRequest->id,
            'qty' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors(['equipment_item_id']);

        $scarce = EquipmentItem::factory()->create(['total_qty' => 1, 'condition' => 'good', 'name' => 'Only One Mic']);
        // Another approved booking already takes the only unit on overlapping dates.
        $other = MaterialRequest::query()->create([
            'requester_type' => 'client',
            'full_name' => 'Other',
            'phone' => '0788000000',
            'email' => 'other@example.com',
            'needed_from' => now()->toDateString(),
            'return_by' => now()->addDays(2)->toDateString(),
            'status' => 'approved',
        ]);
        MaterialRequestItem::query()->create([
            'material_request_id' => $other->id,
            'equipment_item_id' => $scarce->id,
            'qty_requested' => 1,
            'qty_approved' => 1,
            'status' => 'approved',
        ]);

        $this->actingAs($storekeeper)->postJson("/api/material-requests/{$booking->id}/items", [
            'equipment_item_id' => $scarce->id,
            'qty' => 1,
        ])->assertStatus(422);
    }

    public function test_cannot_add_unless_approved_or_without_issue_permission(): void
    {
        $storekeeper = User::factory()->create(['role' => 'storekeeper']);
        $multimedia = User::factory()->create(['role' => 'multimedia']);
        $extra = EquipmentItem::factory()->create(['total_qty' => 2, 'condition' => 'good']);

        $submitted = $this->booking(['status' => 'submitted']);
        $this->actingAs($storekeeper)->postJson("/api/material-requests/{$submitted->id}/items", [
            'equipment_item_id' => $extra->id,
            'qty' => 1,
        ])->assertStatus(422);

        $approved = $this->booking();
        $this->actingAs($multimedia)->postJson("/api/material-requests/{$approved->id}/items", [
            'equipment_item_id' => $extra->id,
            'qty' => 1,
        ])->assertForbidden();
    }

    public function test_cannot_remove_after_issue_and_added_item_is_issued_and_returned(): void
    {
        Storage::fake('public');
        $storekeeper = User::factory()->create(['role' => 'storekeeper']);
        $booking = $this->booking();
        $extra = EquipmentItem::factory()->create(['total_qty' => 3, 'condition' => 'good', 'name' => 'Extra Stand']);

        $lineId = $this->actingAs($storekeeper)->postJson("/api/material-requests/{$booking->id}/items", [
            'equipment_item_id' => $extra->id,
            'qty' => 1,
        ])->assertCreated()->json('item.id');

        $original = $booking->items->first();

        $this->actingAs($storekeeper)->post("/api/material-requests/{$booking->id}/issue", [
            'items' => [
                [
                    'id' => $original->id,
                    'qty_issued' => 1,
                    'issue_proofs' => [UploadedFile::fake()->create('a.jpg', 40, 'image/jpeg')],
                ],
                [
                    'id' => $lineId,
                    'qty_issued' => 1,
                    'issue_proofs' => [UploadedFile::fake()->create('b.jpg', 40, 'image/jpeg')],
                ],
            ],
        ])->assertOk();

        $this->assertSame('issued', $booking->fresh()->status);
        $this->assertSame('issued', MaterialRequestItem::query()->find($lineId)?->status);

        $this->actingAs($storekeeper)
            ->deleteJson("/api/material-requests/{$booking->id}/items/{$lineId}")
            ->assertStatus(422);

        $this->actingAs($storekeeper)->post("/api/material-requests/{$booking->id}/return", [
            'items' => [
                [
                    'id' => $original->id,
                    'qty_returned' => 1,
                    'return_proofs' => [UploadedFile::fake()->create('ra.jpg', 40, 'image/jpeg')],
                ],
                [
                    'id' => $lineId,
                    'qty_returned' => 1,
                    'return_proofs' => [UploadedFile::fake()->create('rb.jpg', 40, 'image/jpeg')],
                ],
            ],
        ])->assertOk();

        $this->assertSame(1, MaterialRequestItem::query()->find($lineId)?->qty_returned);
        $this->assertSame('closed', $booking->fresh()->status);
    }

    public function test_pickup_catalog_lists_photos_and_marks_unavailable(): void
    {
        Storage::fake('public');
        $storekeeper = User::factory()->create(['role' => 'storekeeper']);
        $booking = $this->booking();
        $ok = EquipmentItem::factory()->create(['total_qty' => 2, 'condition' => 'good', 'name' => 'Softbox']);
        EquipmentItem::factory()->create(['total_qty' => 1, 'condition' => 'damaged', 'name' => 'Broken Softbox']);

        $res = $this->actingAs($storekeeper)
            ->getJson("/api/material-requests/{$booking->id}/pickup-catalog?q=Softbox")
            ->assertOk();

        $names = collect($res->json('items'))->pluck('name');
        $this->assertTrue($names->contains('Softbox'));
        $softbox = collect($res->json('items'))->firstWhere('id', $ok->id);
        $this->assertFalse($softbox['unavailable']);
        $broken = collect($res->json('items'))->firstWhere('name', 'Broken Softbox');
        $this->assertTrue($broken['unavailable']);
    }

    public function test_public_status_includes_added_at_pickup_flag(): void
    {
        $storekeeper = User::factory()->create(['role' => 'storekeeper']);
        $booking = $this->booking();
        $extra = EquipmentItem::factory()->create(['total_qty' => 2, 'condition' => 'good', 'name' => 'Cable Kit']);

        $this->actingAs($storekeeper)->postJson("/api/material-requests/{$booking->id}/items", [
            'equipment_item_id' => $extra->id,
            'qty' => 1,
        ])->assertCreated();

        $this->getJson('/api/v1/material-requests/status/'.$booking->uuid)
            ->assertOk()
            ->assertJsonFragment(['name' => 'Cable Kit', 'added_at_pickup' => true]);
    }

    public function test_partially_issued_request_can_still_be_added_to_and_issued(): void
    {
        Storage::fake(config('filesystems.default'));
        Mail::fake();
        $storekeeper = User::factory()->create(['role' => 'storekeeper']);
        $booking = $this->booking(['status' => 'issued']);
        $extra = EquipmentItem::factory()->create(['total_qty' => 2, 'condition' => 'good', 'name' => 'Second Light']);

        $lineId = $this->actingAs($storekeeper)->postJson("/api/material-requests/{$booking->id}/items", [
            'equipment_item_id' => $extra->id,
            'qty' => 1,
        ])->assertCreated()->json('item.id');

        $this->actingAs($storekeeper)->post("/api/material-requests/{$booking->id}/issue", [
            'items' => [[
                'id' => $lineId,
                'qty_issued' => 1,
                'issue_proofs' => [UploadedFile::fake()->create('c.jpg', 40, 'image/jpeg')],
            ]],
        ])->assertOk();

        $this->assertSame('issued', $booking->fresh()->status);
        $this->assertSame($extra->id, MaterialRequestItem::query()->find($lineId)?->equipment_item_id);
        $this->assertSame(1, MaterialRequestItem::query()->find($lineId)?->qty_issued);
    }
}
