<?php

namespace Tests\Feature;

use App\Models\EquipmentItem;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OperationalReminderTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->now = CarbonImmutable::parse('2026-11-08 10:00:00', 'Africa/Kigali');
        CarbonImmutable::setTestNow($this->now);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_staff_see_72_hour_pickups_and_returns_without_contact_details_or_messages(): void
    {
        $camera = EquipmentItem::factory()->create(['name' => 'Camera', 'serial_number' => 'CAM-1', 'total_qty' => 4]);
        $tripod = EquipmentItem::factory()->create(['name' => 'Tripod', 'serial_number' => 'TR-1', 'total_qty' => 4]);
        $admin = User::factory()->create(['role' => 'admin']);
        $storekeeper = User::factory()->create(['role' => 'storekeeper']);
        $viewer = User::factory()->create(['role' => 'multimedia']);

        $soon = $this->booking($camera, 'approved', '2026-11-10 09:00', '2026-11-12 17:00', 'soon@example.com', '0788111001');
        $today = $this->booking($camera, 'approved', null, null, 'today@example.com', '0788111002', '2026-11-08', '2026-11-08');
        $latePickup = $this->booking($camera, 'approved', '2026-11-07 09:00', '2026-11-09 17:00', 'late@example.com', '0788111003');
        $far = $this->booking($camera, 'approved', '2026-11-20 09:00', '2026-11-21 17:00', 'far@example.com', '0788111004');
        $pending = $this->booking($camera, 'submitted', '2026-11-09 11:00', '2026-11-09 18:00', 'pending@example.com', '0788111005');
        $rejected = $this->booking($camera, 'rejected', '2026-11-09 11:00', '2026-11-09 18:00', 'rejected@example.com', '0788111006');
        $partial = $this->booking($tripod, 'issued', '2026-11-08 08:00', '2026-11-09 16:00', 'partial@example.com', '0788111007');
        $partial->items()->update(['qty_approved' => 2, 'qty_issued' => 1, 'qty_returned' => 0, 'status' => 'issued']);
        $done = $this->booking($camera, 'returned', '2026-11-01 09:00', '2026-11-02 17:00', 'done@example.com', '0788111008');
        $done->items()->update(['qty_issued' => 1, 'qty_returned' => 1, 'status' => 'returned']);

        $this->actingAs($viewer)->getJson('/api/operations/reminders')->assertForbidden();

        $response = $this->actingAs($storekeeper)->getJson('/api/operations/reminders')->assertOk();
        $this->actingAs($admin)->getJson('/api/operations/reminders')->assertOk();
        $body = $response->json();
        $this->assertFalse($body['channels']['email']);
        $this->assertFalse($body['channels']['whatsapp']);
        $this->assertFalse($body['dismiss_supported']);
        $this->assertSame(72, $body['window_hours']);
        Mail::assertNothingQueued();

        $encoded = $response->getContent();
        $this->assertStringNotContainsString('0788111001', $encoded);
        $this->assertStringNotContainsString('soon@example.com', $encoded);

        $upcoming = collect($body['upcoming_pickups'])->keyBy('reference_code');
        $this->assertSame('within_3_days', $upcoming[$soon->reference_code]['urgency']);
        $this->assertTrue($upcoming[$soon->reference_code]['time_specified']);
        $this->assertStringContainsString('Africa/Kigali, UTC+2', $upcoming[$soon->reference_code]['label']);
        $this->assertSame('due_today', $upcoming[$today->reference_code]['urgency']);
        $this->assertFalse($upcoming[$today->reference_code]['time_specified']);
        $this->assertStringContainsString('time not specified', $upcoming[$today->reference_code]['label']);
        $this->assertArrayNotHasKey($far->reference_code, $upcoming->all());

        $overduePickups = collect($body['overdue_pickups'])->keyBy('reference_code');
        $this->assertSame('overdue', $overduePickups[$latePickup->reference_code]['urgency']);
        $this->assertSame('hand_out', $overduePickups[$latePickup->reference_code]['action']);

        $pendingCodes = collect($body['pending_approval'])->pluck('reference_code');
        $this->assertTrue($pendingCodes->contains($pending->reference_code));
        $this->assertFalse($pendingCodes->contains($rejected->reference_code));
        $this->assertFalse(collect($body['upcoming_pickups'])->pluck('reference_code')->contains($pending->reference_code));

        $returns = collect($body['returns_due'])->keyBy('reference_code');
        $this->assertSame(1, $returns[$partial->reference_code]['items'][0]['qty']);
        $this->assertSame('Tripod', $returns[$partial->reference_code]['items'][0]['name']);
        $pickups = collect($body['upcoming_pickups'])->merge($body['overdue_pickups'])->keyBy('reference_code');
        $this->assertSame(1, $pickups[$partial->reference_code]['items'][0]['qty']);
        $this->assertFalse(collect($body['returns_due'])->merge($body['overdue_returns'])->pluck('reference_code')->contains($done->reference_code));

        $partial->update([
            'return_by' => '2026-11-20',
            'return_by_at' => CarbonImmutable::parse('2026-11-20 16:00', 'Africa/Kigali')->utc(),
        ]);
        $moved = $this->actingAs($admin)->getJson('/api/operations/reminders')->assertOk();
        $this->assertFalse(collect($moved->json('returns_due'))->pluck('reference_code')->contains($partial->reference_code));
        $this->assertFalse(collect($moved->json('overdue_returns'))->pluck('reference_code')->contains($partial->reference_code));
    }

    private function booking(EquipmentItem $item, string $status, ?string $start, ?string $end, string $email, string $phone, ?string $startDate = null, ?string $endDate = null): MaterialRequest
    {
        $startAt = $start ? CarbonImmutable::parse($start, 'Africa/Kigali')->utc() : null;
        $endAt = $end ? CarbonImmutable::parse($end, 'Africa/Kigali')->utc() : null;
        $request = MaterialRequest::query()->create([
            'requester_type' => 'client',
            'full_name' => 'Borrower',
            'phone' => $phone,
            'email' => $email,
            'needed_from' => $startDate ?? ($startAt?->timezone('Africa/Kigali')->toDateString() ?? '2026-11-08'),
            'return_by' => $endDate ?? ($endAt?->timezone('Africa/Kigali')->toDateString() ?? '2026-11-08'),
            'needed_from_at' => $startAt,
            'return_by_at' => $endAt,
            'status' => $status,
        ]);
        MaterialRequestItem::query()->create([
            'material_request_id' => $request->id,
            'equipment_item_id' => $item->id,
            'qty_requested' => 1,
            'qty_approved' => in_array($status, ['approved', 'issued', 'returned'], true) ? 1 : null,
            'status' => 'pending',
        ]);

        return $request->fresh('items');
    }
}
