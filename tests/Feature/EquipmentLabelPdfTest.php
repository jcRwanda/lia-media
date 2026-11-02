<?php

namespace Tests\Feature;

use App\Models\EquipmentItem;
use App\Models\User;
use App\Services\QrLabelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EquipmentLabelPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_downloads_a4_label_pdf_with_filters(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        EquipmentItem::factory()->create(['name' => 'Camera A', 'category' => 'Camera', 'total_qty' => 2, 'allowed_for_trainees' => true]);
        EquipmentItem::factory()->create(['name' => 'Mic B', 'category' => 'Audio', 'total_qty' => 1, 'allowed_for_trainees' => false]);
        EquipmentItem::factory()->create(['name' => 'Gone C', 'category' => 'Camera', 'total_qty' => 0]);

        $res = $this->actingAs($admin)->get('/api/equipment-labels/pdf?category=Camera&trainee=1');
        $res->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $res->getContent());
        $this->assertStringContainsString('lia-equipment-labels-', $res->headers->get('content-disposition'));

        $this->actingAs($admin)->get('/api/equipment-labels/pdf?ids=999999')->assertOk();
        $this->actingAs($admin)->getJson('/api/equipment-labels/pdf?trainee=maybe')->assertStatus(422);
    }

    public function test_full_in_stock_sheet_of_many_labels_renders_within_memory(): void
    {
        // Regression: 25 in-stock labels (3 A4 pages) exhausted dompdf's 128M limit -> 500.
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (range(1, 30) as $n) {
            EquipmentItem::factory()->create(['name' => "Item $n", 'category' => $n % 2 ? 'Camera' : 'Light', 'total_qty' => 1, 'asset_tag' => null]);
        }
        $old = ini_get('memory_limit');
        ini_set('memory_limit', '128M');
        try {
            $res = $this->actingAs($admin)->get('/api/equipment-labels/pdf?in_stock=1');
        } finally {
            ini_set('memory_limit', $old);
        }
        $res->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    public function test_storekeeper_cannot_download_label_pdf(): void
    {
        $sk = User::factory()->create(['role' => 'storekeeper']);
        $this->actingAs($sk)->getJson('/api/equipment-labels/pdf')->assertForbidden();
    }

    public function test_qr_service_uses_logo_space(): void
    {
        $svc = new QrLabelService;
        $html = $svc->html('https://media.loveinarts.com/q/abc');
        $this->assertStringContainsString('position:absolute', $html);
        $this->assertStringContainsString('<svg', $svc->svg('https://media.loveinarts.com/q/abc'));
    }
}
