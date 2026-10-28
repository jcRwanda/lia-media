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
