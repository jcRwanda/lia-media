<?php

namespace Tests\Feature;

use App\Models\EquipmentItem;
use App\Models\User;
use App\Support\ImageNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EquipmentItemImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_storekeeper_can_add_item_with_three_large_iphone_photos(): void
    {
        Storage::fake('public');
        $storekeeper = User::factory()->create(['role' => 'storekeeper']);

        $this->actingAs($storekeeper)->post('/api/equipment-items', [
            'name' => 'Sony A7 III',
            'category' => 'Cameras',
            'total_qty' => 2,
            'photos' => [
                UploadedFile::fake()->create('IMG_0001.HEIC', 15000, 'image/heic'),
                UploadedFile::fake()->create('IMG_0002.heif', 12000, 'image/heif'),
                UploadedFile::fake()->create('IMG_0003.jpg', 18000, 'image/jpeg'),
            ],
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonCount(3, 'item.images');

        $item = EquipmentItem::query()->firstOrFail();
        $this->assertSame('Cameras', $item->category);
        $this->assertCount(3, $item->images);
        $this->assertNotNull($item->photo_path);
        foreach ($item->images as $image) {
            Storage::disk('public')->assertExists($image->path);
        }
    }

    public function test_photos_over_20mb_return_per_field_errors(): void
    {
        Storage::fake('public');
        $storekeeper = User::factory()->create(['role' => 'storekeeper']);

        $this->actingAs($storekeeper)->post('/api/equipment-items', [
            'name' => 'Tripod',
            'total_qty' => 1,
            'photos' => [
                UploadedFile::fake()->create('ok.jpg', 100, 'image/jpeg'),
                UploadedFile::fake()->create('huge.jpg', 21000, 'image/jpeg'),
                UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
            ],
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['photos.1', 'photos.2'])
            ->assertJsonMissingValidationErrors(['photos.0']);
    }

    public function test_update_accepts_heic_photo(): void
    {
        Storage::fake('public');
        $storekeeper = User::factory()->create(['role' => 'storekeeper']);
        $item = EquipmentItem::factory()->create();

        $this->actingAs($storekeeper)->post("/api/equipment-items/{$item->id}", [
            '_method' => 'PATCH',
            'category' => 'Lighting',
            'photos' => [UploadedFile::fake()->create('IMG_9.heic', 9000, 'image/heic')],
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('item.category', 'Lighting')->assertJsonCount(1, 'item.images');
    }

    public function test_large_image_is_resized_and_saved_as_jpeg(): void
    {
        if (! function_exists('imagecreatetruecolor') && ! extension_loaded('imagick')) {
            $this->markTestSkipped('GD/Imagick not available');
        }
        Storage::fake('public');
        $storekeeper = User::factory()->create(['role' => 'storekeeper']);

        $this->actingAs($storekeeper)->post('/api/equipment-items', [
            'name' => 'Light',
            'total_qty' => 1,
            'photos' => [UploadedFile::fake()->image('big.png', 4000, 3000)],
        ], ['Accept' => 'application/json'])->assertCreated();

        $image = EquipmentItem::query()->firstOrFail()->images->first();
        $this->assertSame('image/jpeg', $image->mime);
        $this->assertSame('big.jpg', $image->name);
        $this->assertStringEndsWith('.jpg', $image->path);
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($image->path));
        $this->assertSame(ImageNormalizer::MAX_EDGE, max($w, $h));
    }
}
