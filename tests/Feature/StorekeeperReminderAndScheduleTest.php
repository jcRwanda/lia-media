<?php

namespace Tests\Feature;

use App\Models\EquipmentItem;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Models\User;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StorekeeperReminderAndScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_date_only_booking_keeps_time_unspecified_and_kigali_datetime_is_stored(): void
    {
        $item = EquipmentItem::factory()->create(['total_qty' => 3]);
        $legacy = $this->postJson('/api/v1/material-requests/submit', $this->payload($item, [
            'email' => 'legacy@example.com',
            'needed_from' => '2026-12-02',
            'return_by' => '2026-12-02',
        ]))->assertCreated();
        $this->assertFalse($legacy->json('schedule.needed_from.time_specified'));
        $this->assertStringContainsString('time not specified', $legacy->json('schedule.needed_from.label'));
        $this->assertNull(MaterialRequest::query()->where('email', 'legacy@example.com')->first()->needed_from_at);

        $timed = $this->postJson('/api/v1/material-requests/submit', $this->payload($item, [
            'email' => 'timed@example.com',
            'phone' => '0788000222',
            'needed_from' => '2026-12-03T09:30',
            'return_by' => '2026-12-03T17:00',
        ]))->assertCreated();
        $this->assertTrue($timed->json('schedule.needed_from.time_specified'));
        $this->assertSame('Africa/Kigali', $timed->json('schedule.timezone'));
        $this->assertStringContainsString('+02:00', $timed->json('schedule.needed_from.at'));
        $stored = MaterialRequest::query()->where('email', 'timed@example.com')->first();
        $this->assertSame(
            '2026-12-03 07:30:00',
            $stored->needed_from_at?->utc()->format('Y-m-d H:i:s'),
        );

        $this->postJson('/api/v1/material-requests/submit', $this->payload($item, [
            'email' => 'backwards@example.com',
            'phone' => '0788000333',
            'needed_from' => '2026-12-04T18:00',
            'return_by' => '2026-12-04T09:00',
        ]))->assertStatus(422)->assertJsonValidationErrors('return_by');
    }

    public function test_timed_intervals_touching_at_an_endpoint_do_not_overlap(): void
    {
        $item = EquipmentItem::factory()->create(['name' => 'Timed body', 'total_qty' => 2]);
        $this->timedBooking($item, '2026-12-10 09:00', '2026-12-10 10:00');

        $touching = $this->postJson('/api/v1/material-requests/submit', $this->payload($item, [
            'email' => 'touch@example.com',
            'needed_from' => '2026-12-10T10:00',
            'return_by' => '2026-12-10T11:00',
        ]))->assertCreated();
        $this->assertSame(0, $touching->json('booking_overlap.warnings.0.active_requests_before'));

        $inside = $this->postJson('/api/v1/material-requests/submit', $this->payload($item, [
            'email' => 'inside@example.com',
            'phone' => '0788000444',
            'needed_from' => '2026-12-10T09:30',
            'return_by' => '2026-12-10T09:45',
        ]))->assertCreated();
        $this->assertSame('not_guaranteed', $inside->json('booking_overlap.level'));
        $this->assertSame(1, $inside->json('booking_overlap.warnings.0.active_requests_before'));
    }

    public function test_admin_reminder_opens_whatsapp_to_the_storekeeper_and_does_not_record_a_return(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $storekeeper = User::factory()->create(['role' => 'storekeeper']);
        app(SettingsService::class)->put([
            'storekeeper_whatsapp' => '+250 788 000 111',
            'storekeeper_contact_name' => 'Desk',
        ]);
        $item = EquipmentItem::factory()->create(['name' => 'Camera body', 'serial_number' => 'SN-9', 'total_qty' => 2]);
        $booking = MaterialRequest::query()->create([
            'requester_type' => 'client',
            'full_name' => 'Aline Borrower',
            'phone' => '0788999888',
            'email' => 'aline.private@example.com',
            'national_id' => '1199000111222333',
            'project_name' => 'Film',
            'needed_from' => '2026-12-06',
            'return_by' => '2026-12-08',
            'return_by_at' => CarbonImmutable::parse('2026-12-08 17:00', 'Africa/Kigali')->utc(),
            'status' => 'issued',
        ]);
        MaterialRequestItem::query()->create([
            'material_request_id' => $booking->id,
            'equipment_item_id' => $item->id,
            'qty_requested' => 1,
            'qty_approved' => 1,
            'qty_issued' => 1,
            'status' => 'issued',
        ]);

        $this->actingAs($storekeeper)->getJson('/api/material-requests/'.$booking->id.'/storekeeper-reminder?purpose=return')->assertForbidden();

        $preview = $this->actingAs($admin)->getJson('/api/material-requests/'.$booking->id.'/storekeeper-reminder?purpose=return')->assertOk();
        $this->assertFalse($preview->json('records_return'));
        $this->assertFalse($preview->json('sent'));
        $this->assertSame('250788000111', $preview->json('recipient.whatsapp'));
        $this->assertStringStartsWith('https://wa.me/250788000111?text=', $preview->json('whatsapp_url'));
        $this->assertStringNotContainsString('0788999888', $preview->json('whatsapp_url'));
        $text = urldecode(explode('text=', $preview->json('whatsapp_url'), 2)[1]);
        $this->assertStringContainsString($booking->reference_code, $text);
        $this->assertStringContainsString('Camera body x1', $text);
        $this->assertStringContainsString('Africa/Kigali, UTC+2', $text);
        $this->assertStringContainsString('/material-requests/'.$booking->id, $text);
        $this->assertStringNotContainsString('aline.private@example.com', $text);
        $this->assertStringNotContainsString('1199000111222333', $text);
        $this->assertStringNotContainsString('certificate', strtolower($text));
        $this->assertSame('issued', $booking->fresh()->status);

        app(SettingsService::class)->put(['storekeeper_whatsapp' => '']);
        User::query()->whereKey($storekeeper->id)->update(['phone' => null]);
        $missing = $this->actingAs($admin)->getJson('/api/material-requests/'.$booking->id.'/storekeeper-reminder?purpose=confirm_return')->assertOk();
        $this->assertTrue($missing->json('setup_required'));
        $this->assertNull($missing->json('whatsapp_url'));
        $this->assertStringContainsString('never used', $missing->json('guidance'));
    }

    private function timedBooking(EquipmentItem $item, string $start, string $end): void
    {
        MaterialRequest::query()->create([
            'requester_type' => 'client',
            'full_name' => 'Earlier',
            'phone' => '0788111000',
            'email' => 'earlier@example.com',
            'needed_from' => CarbonImmutable::parse($start, 'Africa/Kigali')->toDateString(),
            'return_by' => CarbonImmutable::parse($end, 'Africa/Kigali')->toDateString(),
            'needed_from_at' => CarbonImmutable::parse($start, 'Africa/Kigali')->utc(),
            'return_by_at' => CarbonImmutable::parse($end, 'Africa/Kigali')->utc(),
            'status' => 'submitted',
        ])->items()->create([
            'equipment_item_id' => $item->id,
            'qty_requested' => 1,
            'status' => 'pending',
        ]);
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
            'project_name' => 'Time',
            'province_res' => 'Kigali',
            'district_res' => 'Gasabo',
            'sector_res' => 'Remera',
            'cell_res' => 'Rukiri',
            'village_res' => 'Amahoro',
            'emergency_contact_name' => 'Mum',
            'emergency_contact_phone' => '0788000222',
            'relationship' => 'Mother',
            'project_summary' => 'Shoot',
            'needed_from' => '2026-12-20',
            'return_by' => '2026-12-21',
            'terms_accepted' => true,
            'consent_declaration' => true,
            'signature' => 'data:image/png;base64,'.base64_encode('signature'),
            'id_document' => 'data:image/png;base64,'.base64_encode('id'),
            'items' => [['equipment_item_id' => $item->id, 'qty' => 1]],
        ], $overrides);
    }
}
