<?php

namespace Tests\Feature;

use App\Models\EquipmentItem;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorekeeperScanAccessTest extends TestCase
{
    use RefreshDatabase;

    /** Mirrors production: storekeeper assigned via a DB role with only store permissions. */
    public function test_db_role_storekeeper_gets_scan_ability_and_can_scan(): void
    {
        $role = Role::query()->updateOrCreate(['slug' => 'storekeeper'], [
            'name' => 'Storekeeper', 'is_system' => true, 'is_admin' => false,
            'permissions' => ['material-requests.view', 'material-requests.issue', 'material-requests.return', 'equipment.manage'],
        ]);
        $sk = User::factory()->create(['role' => 'storekeeper', 'role_id' => $role->id, 'status' => 'active', 'must_change_password' => false]);
        $item = EquipmentItem::factory()->create();

        $this->actingAs($sk)->getJson('/api/auth/me')->assertOk()
            ->assertJsonFragment(['material-requests.issue']);
        $this->actingAs($sk)->getJson("/api/equipment-scan/{$item->qr_token}")->assertOk();
    }

    public function test_role_without_issue_cannot_scan(): void
    {
        $u = User::factory()->create(['role' => 'multimedia']);
        $item = EquipmentItem::factory()->create();
        $this->actingAs($u)->getJson("/api/equipment-scan/{$item->qr_token}")->assertForbidden();
    }
}
