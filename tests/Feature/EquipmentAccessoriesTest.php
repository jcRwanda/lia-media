<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\EquipmentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EquipmentAccessoriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_storekeeper_links_and_clears_accessories(): void
    {
        $sk = User::factory()->create(['role' => 'storekeeper']);
        $cam = EquipmentItem::factory()->create(['name' => 'Camera']);
        $bat = EquipmentItem::factory()->create(['name' => 'Battery']);
        $lens = EquipmentItem::factory()->create(['name' => 'Lens']);
        $h = ['Accept' => 'application/json'];

        $this->actingAs($sk)->patch("/api/equipment-items/{$cam->id}", ['accessory_ids' => [$bat->id, $lens->id, $cam->id]], $h)
            ->assertOk()->assertJsonPath('item.accessory_ids', [$bat->id, $lens->id]);
        $this->assertCount(2, $cam->fresh()->accessories);

        $this->actingAs($sk)->patch("/api/equipment-items/{$cam->id}", ['name' => 'Camera 2'], $h)->assertOk();
        $this->assertCount(2, $cam->fresh()->accessories, 'untouched when not sent');

        $this->actingAs($sk)->patch("/api/equipment-items/{$cam->id}", ['sync_accessories' => 1], $h)->assertOk();
        $this->assertCount(0, $cam->fresh()->accessories);

        $this->actingAs($sk)->post('/api/equipment-items', ['name' => 'Kit', 'total_qty' => 1, 'accessory_ids' => [$bat->id]], $h)
            ->assertCreated()->assertJsonPath('item.accessories.0.name', 'Battery');
    }

    public function test_catalog_and_schema_expose_accessories_and_images(): void
    {
        $cam = EquipmentItem::factory()->create(['name' => 'Camera', 'allowed_for_trainees' => true]);
        $bat = EquipmentItem::factory()->create(['name' => 'Battery', 'allowed_for_trainees' => true]);
        $lens = EquipmentItem::factory()->create(['name' => 'Lens', 'allowed_for_trainees' => false]);
        $cam->accessories()->sync([$bat->id, $lens->id]);
        $cam->images()->create(['path' => 'material-requests/equipment/a.jpg', 'name' => 'a.jpg', 'mime' => 'image/jpeg', 'sort_order' => 0]);
        $cam->images()->create(['path' => 'material-requests/equipment/b.jpg', 'name' => 'b.jpg', 'mime' => 'image/jpeg', 'sort_order' => 1]);

        $all = $this->getJson('/api/v1/material-requests/catalog')->assertOk()->json('items');
        $c = collect($all)->firstWhere('name', 'Camera');
        $this->assertCount(2, $c['accessories']);
        $this->assertCount(2, $c['image_urls']);

        $trainee = $this->getJson('/api/v1/material-requests/catalog?type=trainee')->assertOk()->json('items');
        $c = collect($trainee)->firstWhere('name', 'Camera');
        $this->assertSame(['Battery'], array_column($c['accessories'], 'name'));

        ApiClient::query()->create(['name' => 'portal', 'key_hash' => Hash::make('k123'), 'active' => true]);
        $schema = $this->withHeaders(['X-LIA-Key' => 'k123'])->getJson('/api/v1/material-requests/form-schema?type=trainee')->assertOk()->json('catalog');
        $this->assertNull(collect($schema)->firstWhere('name', 'Lens'));
        $this->assertSame(['Battery'], array_column(collect($schema)->firstWhere('name', 'Camera')['accessories'], 'name'));
    }
}
