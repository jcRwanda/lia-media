<?php

namespace Tests\Feature;

use App\Models\EquipmentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class EquipmentImageManageTest extends TestCase
{
    use RefreshDatabase;

    private function itemWithImages(User $user): EquipmentItem
    {
        $this->actingAs($user)->post('/api/equipment-items', [
            'name' => 'Canon R6', 'total_qty' => 1,
            'photos' => [
                UploadedFile::fake()->create('a.jpg', 10, 'image/jpeg'),
                UploadedFile::fake()->create('b.jpg', 10, 'image/jpeg'),
                UploadedFile::fake()->create('c.heic', 10, 'image/heic'),
            ],
        ], ['Accept' => 'application/json'])->assertCreated();

        return EquipmentItem::query()->with('images')->firstOrFail();
    }

    public function test_storekeeper_can_reorder_set_main_and_remove_images(): void
    {
        $sk = User::factory()->create(['role' => 'storekeeper']);
        $item = $this->itemWithImages($sk);
        [$a, $b, $c] = $item->images->all();
        $this->assertSame($a->path, $item->photo_path);

        $this->actingAs($sk)->putJson("/api/equipment-items/{$item->id}/images/order", ['ids' => [$c->id, $a->id, $b->id]])
            ->assertOk()->assertJsonPath('item.images.0.id', $c->id)->assertJsonPath('item.images.0.is_main', true);
        $this->assertSame($c->path, $item->fresh()->photo_path);

        $this->actingAs($sk)->postJson("/api/equipment-items/{$item->id}/images/{$b->id}/main")
            ->assertOk()->assertJsonPath('item.images.0.id', $b->id);
        $this->assertSame($b->path, $item->fresh()->photo_path);

        $this->actingAs($sk)->deleteJson("/api/equipment-items/{$item->id}/images/{$b->id}")
            ->assertOk()->assertJsonCount(2, 'item.images')->assertJsonPath('item.images.0.id', $c->id);
        $this->assertSame($c->path, $item->fresh()->photo_path);

        $this->actingAs($sk)->deleteJson("/api/equipment-items/{$item->id}/images/{$c->id}")->assertOk();
        $this->actingAs($sk)->deleteJson("/api/equipment-items/{$item->id}/images/{$a->id}")->assertOk()->assertJsonCount(0, 'item.images');
        $this->assertNull($item->fresh()->photo_path);
    }

    public function test_admin_can_manage_and_image_must_belong_to_item(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $item = $this->itemWithImages($admin);
        $other = EquipmentItem::query()->create(['name' => 'Other', 'total_qty' => 1]);
        $img = $item->images->first();

        $this->actingAs($admin)->deleteJson("/api/equipment-items/{$other->id}/images/{$img->id}")->assertNotFound();
        $this->actingAs($admin)->postJson("/api/equipment-items/{$item->id}/images/{$img->id}/main")->assertOk();
    }

    public function test_role_without_equipment_manage_cannot_manage_images(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $item = $this->itemWithImages($admin);
        $viewer = User::factory()->create(['role' => 'multimedia']);
        $this->actingAs($viewer)->deleteJson("/api/equipment-items/{$item->id}/images/{$item->images->first()->id}")->assertForbidden();
    }

    public function test_new_uploads_append_after_existing_images(): void
    {
        $sk = User::factory()->create(['role' => 'storekeeper']);
        $item = $this->itemWithImages($sk);
        $this->actingAs($sk)->post("/api/equipment-items/{$item->id}", [
            '_method' => 'PATCH',
            'photos' => [UploadedFile::fake()->create('d.jpg', 10, 'image/jpeg')],
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonCount(4, 'item.images')->assertJsonPath('item.images.3.name', 'd.jpg');
    }
}
