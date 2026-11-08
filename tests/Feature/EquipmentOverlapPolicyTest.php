<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\EquipmentItem;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EquipmentOverlapPolicyTest extends TestCase
{
    use RefreshDatabase;

    private string $from;

    private string $to;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->from = now()->addDays(10)->toDateString();
        $this->to = now()->addDays(12)->toDateString();
    }

    public function test_positions_one_through_three_are_accepted_and_the_fourth_is_rejected(): void
    {
        $item = EquipmentItem::factory()->create(['name' => 'Canon R body', 'total_qty' => 5]);
        $levels = [];
        foreach ([0, 1, 2] as $existing) {
            $response = $this->postJson('/api/v1/material-requests/submit', $this->payload($item, [
                'email' => "queue{$existing}@example.com",
                'phone' => '078800000'.$existing,
            ]))->assertCreated();
            $levels[] = $response->json('booking_overlap.level');
            $this->assertSame($existing, $response->json('booking_overlap.warnings.0.active_requests_before'));
            $this->assertSame($existing + 1, $response->json('booking_overlap.warnings.0.position'));
            $this->assertFalse($response->json('booking_overlap.warnings.0.guaranteed'));
        }

        $this->assertSame(['pending', 'not_guaranteed', 'not_guaranteed'], $levels);

        $this->postJson('/api/v1/material-requests/submit', $this->payload($item, [
            'email' => 'fourth@example.com',
            'phone' => '0788000009',
        ]))->assertStatus(422)->assertJsonValidationErrors('needed_from');

        $this->assertSame(3, MaterialRequest::query()->count());
        $this->assertNull(MaterialRequest::query()->where('email', 'fourth@example.com')->first());
    }

    public function test_unapproved_requests_count_and_partial_overlaps_count(): void
    {
        $item = EquipmentItem::factory()->create(['name' => 'Light', 'total_qty' => 5]);
        $this->existing($item, $this->from, $this->to, 'submitted');
        $partialFrom = now()->addDays(12)->toDateString();
        $partialTo = now()->addDays(14)->toDateString();

        $overlap = $this->postJson('/api/v1/material-requests/submit', $this->payload($item, [
            'email' => 'partial@example.com',
            'needed_from' => $partialFrom,
            'return_by' => $partialTo,
        ]))->assertCreated();
        $this->assertSame('not_guaranteed', $overlap->json('booking_overlap.level'));
        $this->assertSame(1, $overlap->json('booking_overlap.warnings.0.active_requests_before'));

        $clearFrom = now()->addDays(15)->toDateString();
        $clear = $this->postJson('/api/v1/material-requests/submit', $this->payload($item, [
            'email' => 'clear@example.com',
            'phone' => '0788111222',
            'needed_from' => $clearFrom,
            'return_by' => now()->addDays(16)->toDateString(),
        ]))->assertCreated();
        $this->assertSame('pending', $clear->json('booking_overlap.level'));
        $this->assertSame(0, $clear->json('booking_overlap.warnings.0.active_requests_before'));
    }

    public function test_rejected_closed_and_returned_requests_do_not_count(): void
    {
        $item = EquipmentItem::factory()->create(['name' => 'Mic', 'total_qty' => 5]);
        foreach (['rejected', 'closed', 'returned'] as $status) {
            $this->existing($item, $this->from, $this->to, $status);
        }

        $response = $this->postJson('/api/v1/material-requests/submit', $this->payload($item, [
            'email' => 'fresh@example.com',
        ]))->assertCreated();
        $this->assertSame(0, $response->json('booking_overlap.warnings.0.active_requests_before'));
    }

    public function test_duplicate_lines_on_one_booking_count_once_and_a_multi_item_request_is_all_or_nothing(): void
    {
        $crowded = EquipmentItem::factory()->create(['name' => 'Crowded cam', 'total_qty' => 5]);
        $free = EquipmentItem::factory()->create(['name' => 'Free tripod', 'total_qty' => 5]);
        $duplicate = $this->existing($crowded, $this->from, $this->to, 'submitted');
        MaterialRequestItem::query()->create([
            'material_request_id' => $duplicate->id,
            'equipment_item_id' => $crowded->id,
            'qty_requested' => 1,
            'status' => 'pending',
        ]);
        foreach (range(1, 2) as $i) {
            $this->existing($crowded, $this->from, $this->to, 'changes_requested', "other{$i}@example.com");
        }

        $this->postJson('/api/v1/material-requests/submit', $this->payload($crowded, [
            'email' => 'both@example.com',
            'items' => [
                ['equipment_item_id' => $crowded->id, 'qty' => 1],
                ['equipment_item_id' => $free->id, 'qty' => 1],
            ],
        ]))->assertStatus(422)->assertJsonValidationErrors('items.0');

        $this->assertNull(MaterialRequest::query()->where('email', 'both@example.com')->first());
        $this->assertSame(0, MaterialRequestItem::query()->where('equipment_item_id', $free->id)->count());
    }

    public function test_resubmit_excludes_itself_and_does_not_change_a_rejected_edit(): void
    {
        $item = EquipmentItem::factory()->create(['name' => 'Body', 'total_qty' => 5]);
        $other = EquipmentItem::factory()->create(['name' => 'Full kit', 'total_qty' => 5]);
        $mine = $this->existing($item, $this->from, $this->to, 'changes_requested', 'mine@example.com');
        $this->existing($item, $this->from, $this->to, 'submitted', 'a@example.com');
        $this->existing($item, $this->from, $this->to, 'submitted', 'b@example.com');
        foreach (range(1, 3) as $i) {
            $this->existing($other, $this->from, $this->to, 'submitted', "full{$i}@example.com");
        }

        $this->postJson('/api/v1/material-requests/status/'.$mine->uuid.'/resubmit', [
            'needed_from' => $this->from,
            'return_by' => $this->to,
            'items' => [['equipment_item_id' => $item->id, 'qty' => 1]],
        ])->assertOk();
        $this->assertSame($item->id, $mine->fresh()->items()->first()->equipment_item_id);

        $moving = $this->existing($other, $this->from, $this->to, 'changes_requested', 'moving@example.com');
        $this->postJson('/api/v1/material-requests/status/'.$moving->uuid.'/resubmit', [
            'needed_from' => $this->from,
            'return_by' => $this->to,
            'items' => [['equipment_item_id' => $item->id, 'qty' => 1]],
        ])->assertStatus(422);

        $this->assertSame($other->id, $moving->fresh()->items()->first()->equipment_item_id);
        $this->assertSame('changes_requested', $moving->fresh()->status);
    }

    public function test_approval_respects_stock_but_issue_of_a_valid_reservation_is_not_blocked(): void
    {
        Storage::fake(config('filesystems.default'));
        $item = EquipmentItem::factory()->create(['name' => 'Only body', 'total_qty' => 1]);
        $first = $this->existing($item, $this->from, $this->to, 'submitted', 'first@example.com');
        $second = $this->existing($item, $this->from, $this->to, 'submitted', 'second@example.com');
        $admin = User::factory()->create(['role' => 'admin']);
        $storekeeper = User::factory()->create(['role' => 'storekeeper']);

        $this->actingAs($admin)->postJson("/api/material-requests/{$first->id}/approve", [])->assertOk();
        $this->actingAs($admin)->postJson("/api/material-requests/{$second->id}/approve", [])->assertStatus(422);

        $this->assertSame('approved', $first->fresh()->status);
        $this->assertSame('submitted', $second->fresh()->status);

        $this->actingAs($storekeeper)->post("/api/material-requests/{$first->id}/issue", [
            'items' => [[
                'id' => $first->items()->first()->id,
                'qty_issued' => 1,
                'issue_proofs' => [UploadedFile::fake()->create('hand.jpg', 40, 'image/jpeg')],
            ]],
        ])->assertOk();
        $this->assertSame('issued', $first->fresh()->status);
    }

    public function test_availability_reports_pending_count_without_reducing_stock(): void
    {
        $item = EquipmentItem::factory()->create(['name' => 'Listed', 'total_qty' => 4]);
        $this->existing($item, $this->from, $this->to, 'submitted');
        $this->existing($item, $this->from, $this->to, 'approved', 'approved@example.com');
        ApiClient::query()->create([
            'name' => 'Portal',
            'key_hash' => Hash::make('portal-key'),
            'prefill_secret' => 'portal-key',
            'active' => true,
        ]);

        $day = $this->withHeaders(['X-LIA-Key' => 'portal-key'])->getJson('/api/v1/material-requests/availability?'.http_build_query([
            'item_ids' => [$item->id],
            'from' => $this->from,
            'to' => $this->from,
        ]))->assertOk()->json('items.0.daily.'.$this->from);

        $this->assertSame(1, $day['pending_request_count']);
        $this->assertSame(2, $day['active_request_count']);
        $this->assertSame(3, $day['available_qty']);
    }

    private function existing(EquipmentItem $item, string $from, string $to, string $status, string $email = 'existing@example.com'): MaterialRequest
    {
        $request = MaterialRequest::query()->create([
            'requester_type' => 'client',
            'full_name' => 'Existing',
            'phone' => '0788111000',
            'email' => $email,
            'project_name' => 'Existing',
            'needed_from' => $from,
            'return_by' => $to,
            'status' => $status,
        ]);
        MaterialRequestItem::query()->create([
            'material_request_id' => $request->id,
            'equipment_item_id' => $item->id,
            'qty_requested' => 1,
            'qty_approved' => $status === 'approved' || $status === 'issued' ? 1 : null,
            'status' => 'pending',
        ]);

        return $request;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(EquipmentItem $item, array $overrides = []): array
    {
        return array_merge([
            'requester_type' => 'client',
            'full_name' => 'New Booker',
            'phone' => '0788000111',
            'email' => 'new@example.com',
            'project_name' => 'Overlap',
            'province_res' => 'Kigali',
            'district_res' => 'Gasabo',
            'sector_res' => 'Remera',
            'cell_res' => 'Rukiri',
            'village_res' => 'Amahoro',
            'emergency_contact_name' => 'Mum',
            'emergency_contact_phone' => '0788000222',
            'relationship' => 'Mother',
            'project_summary' => 'Shoot',
            'needed_from' => $this->from,
            'return_by' => $this->to,
            'terms_accepted' => true,
            'consent_declaration' => true,
            'signature' => 'data:image/png;base64,'.base64_encode('signature'),
            'id_document' => 'data:image/png;base64,'.base64_encode('id'),
            'items' => [['equipment_item_id' => $item->id, 'qty' => 1]],
        ], $overrides);
    }
}
