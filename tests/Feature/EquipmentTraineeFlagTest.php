<?php

namespace Tests\Feature;

use App\Models\EquipmentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EquipmentTraineeFlagTest extends TestCase
{
    use RefreshDatabase;

    public static function truthy(): array
    {
        return [['1'], ['true'], ['on'], ['yes'], [true], [1]];
    }

    public static function falsy(): array
    {
        return [['0'], ['false'], ['off'], ['no'], [false], [0]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('truthy')]
    public function test_create_accepts_formdata_true_values(mixed $value): void
    {
        $sk = User::factory()->create(['role' => 'storekeeper']);
        $res = $this->actingAs($sk)->post('/api/equipment-items', [
            'name' => 'Tripod', 'total_qty' => 2, 'allowed_for_trainees' => $value,
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('item.allowed_for_trainees', true);

        $this->assertTrue(EquipmentItem::find($res->json('item.id'))->allowed_for_trainees);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('falsy')]
    public function test_edit_unticks_and_reticks_and_reloads(mixed $value): void
    {
        $sk = User::factory()->create(['role' => 'storekeeper']);
        $item = EquipmentItem::factory()->create(['allowed_for_trainees' => true]);

        $this->actingAs($sk)->post("/api/equipment-items/{$item->id}", ['_method' => 'PATCH', 'allowed_for_trainees' => $value], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('item.allowed_for_trainees', false);
        $this->getJson('/api/equipment-items')->assertOk()->assertJsonFragment(['id' => $item->id, 'allowed_for_trainees' => false]);
        $this->assertFalse($item->fresh()->allowed_for_trainees);

        $this->actingAs($sk)->post("/api/equipment-items/{$item->id}", ['_method' => 'PATCH', 'allowed_for_trainees' => 'true'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('item.allowed_for_trainees', true);
        $this->assertTrue($item->fresh()->allowed_for_trainees);
    }

    public function test_trainee_catalog_follows_the_flag(): void
    {
        $sk = User::factory()->create(['role' => 'storekeeper']);
        $item = EquipmentItem::factory()->create(['allowed_for_trainees' => false, 'name' => 'Light stand']);

        $ids = fn () => collect(app(\App\Http\Controllers\Api\MaterialRequestPublicController::class)
            ->catalog(\Illuminate\Http\Request::create('/x', 'GET', ['type' => 'trainee']))->getData(true)['items'])->pluck('id')->all();

        $this->assertNotContains($item->id, $ids());
        $this->actingAs($sk)->post("/api/equipment-items/{$item->id}", ['_method' => 'PATCH', 'allowed_for_trainees' => '1'], ['Accept' => 'application/json'])->assertOk();
        $this->assertContains($item->id, $ids());
    }

    public function test_invalid_value_is_rejected(): void
    {
        $sk = User::factory()->create(['role' => 'storekeeper']);
        $item = EquipmentItem::factory()->create(['allowed_for_trainees' => true]);
        $this->actingAs($sk)->post("/api/equipment-items/{$item->id}", ['_method' => 'PATCH', 'allowed_for_trainees' => 'maybe'], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->assertTrue($item->fresh()->allowed_for_trainees);
    }
}
