<?php

namespace Tests\Feature;

use App\Models\EquipmentItem;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Models\MaterialRequestItemProof;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StorekeeperPortalTest extends TestCase
{
    use RefreshDatabase;

    private function booking(array $overrides = [], array $itemOverrides = []): MaterialRequest
    {
        $request = MaterialRequest::query()->create(array_merge([
            'requester_type' => 'client',
            'full_name' => 'Test Requester',
            'phone' => '0788123456',
            'email' => 'requester@example.com',
            'project_location' => 'Kigali, Nyarugenge',
            'needed_from' => now()->toDateString(),
            'return_by' => now()->addDays(2)->toDateString(),
            'status' => 'approved',
        ], $overrides));

        $equipment = EquipmentItem::factory()->create(['total_qty' => 10]);

        MaterialRequestItem::query()->create(array_merge([
            'material_request_id' => $request->id,
            'equipment_item_id' => $equipment->id,
            'qty_requested' => 2,
            'qty_approved' => 2,
            'status' => 'approved',
        ], $itemOverrides));

        return $request->fresh(['items']);
    }

    public function test_storekeeper_bookings_are_grouped_into_tabs(): void
    {
        $storekeeper = User::factory()->create(['role' => 'storekeeper']);

        $this->booking();
        $out = $this->booking(['status' => 'issued'], ['qty_issued' => 2, 'status' => 'issued']);
        $overdue = $this->booking(
            ['status' => 'issued', 'return_by' => now()->subDays(2)->toDateString()],
            ['qty_issued' => 2, 'status' => 'issued'],
        );
        $this->booking(['status' => 'closed']);

        $this->actingAs($storekeeper)
            ->getJson('/api/storekeeper/bookings?tab=to_hand_out')
            ->assertOk()
            ->assertJsonPath('counts.to_hand_out', 1)
            ->assertJsonPath('counts.out_now', 2)
            ->assertJsonPath('counts.due_overdue', 1)
            ->assertJsonPath('counts.history', 1);

        $this->getJson('/api/storekeeper/bookings?tab=out_now')
            ->assertOk()
            ->assertJsonPath('tab', 'out_now')
            ->assertJsonFragment(['id' => $out->id])
            ->assertJsonFragment(['id' => $overdue->id]);
    }

    public function test_other_roles_cannot_open_storekeeper_portal(): void
    {
        $multimedia = User::factory()->create(['role' => 'multimedia']);

        $this->actingAs($multimedia)->getJson('/api/storekeeper/bookings')->assertForbidden();
    }

    public function test_hand_out_and_check_in_accept_multiple_proof_images(): void
    {
        Storage::fake('public');
        $storekeeper = User::factory()->create(['role' => 'storekeeper']);
        $booking = $this->booking();
        $item = $booking->items->first();

        $this->actingAs($storekeeper)->post("/api/material-requests/{$booking->id}/issue", [
            'items' => [
                [
                    'id' => $item->id,
                    'qty_issued' => 2,
                    'issue_condition' => 'Good, lenses clean',
                    'note' => 'Handed to requester at reception',
                    'issue_proofs' => [
                        UploadedFile::fake()->create('handout-1.jpg', 100, 'image/jpeg'),
                        UploadedFile::fake()->create('handout-2.jpg', 100, 'image/jpeg'),
                    ],
                ],
            ],
        ])->assertOk();

        $item->refresh();
        $this->assertSame('issued', $item->status);
        $this->assertNotNull($item->issued_at);
        $this->assertSame($storekeeper->id, $item->issued_by);
        $this->assertSame(2, MaterialRequestItemProof::query()->where('material_request_item_id', $item->id)->where('type', 'issue')->count());

        $this->actingAs($storekeeper)->post("/api/material-requests/{$booking->id}/return", [
            'items' => [
                [
                    'id' => $item->id,
                    'qty_returned' => 2,
                    'return_condition' => 'Returned in good condition',
                    'return_proofs' => [
                        UploadedFile::fake()->create('return-1.jpg', 100, 'image/jpeg'),
                        UploadedFile::fake()->create('return-2.jpg', 100, 'image/jpeg'),
                    ],
                ],
            ],
        ])->assertOk();

        $item->refresh();
        $this->assertSame(2, $item->qty_returned);
        $this->assertNotNull($item->returned_at);
        $this->assertSame($storekeeper->id, $item->received_by);
        $this->assertSame(2, MaterialRequestItemProof::query()->where('material_request_item_id', $item->id)->where('type', 'return')->count());
        $this->assertSame('closed', $booking->fresh()->status);
    }

    public function test_hand_out_still_requires_photo_proof(): void
    {
        $storekeeper = User::factory()->create(['role' => 'storekeeper']);
        $booking = $this->booking();
        $item = $booking->items->first();

        $this->actingAs($storekeeper)->postJson("/api/material-requests/{$booking->id}/issue", [
            'items' => [['id' => $item->id, 'qty_issued' => 2]],
        ])->assertStatus(422)->assertJsonValidationErrors(['items.0.issue_proof']);
    }

    public function test_storekeeper_can_manage_equipment_with_multiple_images(): void
    {
        Storage::fake('public');
        $storekeeper = User::factory()->create(['role' => 'storekeeper']);

        $create = $this->actingAs($storekeeper)->post('/api/equipment-items', [
            'name' => 'Sony A7 Camera',
            'category' => 'Camera',
            'serial_number' => 'SN-123',
            'condition' => 'Good',
            'total_qty' => 3,
            'allowed_for_trainees' => 1,
            'photos' => [
                UploadedFile::fake()->create('camera-1.jpg', 100, 'image/jpeg'),
                UploadedFile::fake()->create('camera-2.jpg', 100, 'image/jpeg'),
            ],
        ])->assertCreated();

        $id = $create->json('item.id');
        $this->assertCount(2, $create->json('item.images'));

        $this->actingAs($storekeeper)->post("/api/equipment-items/{$id}", [
            '_method' => 'PATCH',
            'condition' => 'Needs service',
            'total_qty' => 4,
            'photos' => [UploadedFile::fake()->create('camera-3.jpg', 100, 'image/jpeg')],
        ])->assertOk()
            ->assertJsonPath('item.condition', 'Needs service')
            ->assertJsonPath('item.total_qty', 4)
            ->assertJsonCount(3, 'item.images');
    }

    public function test_booking_detail_exposes_whatsapp_link_with_250_phone(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $booking = $this->booking(['phone' => '0788123456']);

        $response = $this->actingAs($admin)->getJson("/api/material-requests/{$booking->id}")->assertOk();

        $url = $response->json('request.whatsapp_url');
        $this->assertStringStartsWith('https://wa.me/250788123456?text=', $url);
        $this->assertStringContainsString(rawurlencode($booking->reference_code), $url);
        $this->assertStringContainsString('Booking%20summary', $url);
    }
}
