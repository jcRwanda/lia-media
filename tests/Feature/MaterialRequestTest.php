<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\EquipmentItem;
use App\Models\MaterialRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MaterialRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_catalog_requires_key_or_allows_with_cors_and_key(): void
    {
        config(['material-requests.allowed_origins' => ['https://learning.loveinarts.com']]);
        $item = EquipmentItem::factory()->create(['total_qty' => 5]);

        $res = $this->withHeaders(['Origin' => 'https://learning.loveinarts.com'])->getJson('/api/v1/material-requests/catalog');
        $res->assertStatus(200);

        $key = 'testkey123';
        ApiClient::query()->create(['name' => 'portal', 'key_hash' => Hash::make($key), 'allowed_origin' => 'https://learning.loveinarts.com', 'active' => true]);
        $res2 = $this->withHeaders(['Origin' => 'https://learning.loveinarts.com', 'X-LIA-Key' => $key])->getJson('/api/v1/material-requests/catalog');
        $res2->assertStatus(200)->assertJsonStructure(['items']);
    }

    public function test_type_specific_validation(): void
    {
        config(['material-requests.allowed_origins' => ['https://learning.loveinarts.com']]);
        $item = EquipmentItem::factory()->create(['total_qty' => 5]);

        $payload = [
            'requester_type' => 'trainee',
            'full_name' => 'Test User',
            'phone' => '123',
            'email' => 't@example.com',
            'emergency_contact_name' => 'EC',
            'emergency_contact_phone' => '456',
            'purpose_project_description' => 'Project',
            'needed_from' => now()->toDateString(),
            'return_by' => now()->addDay()->toDateString(),
            'terms_accepted' => true,
            'items' => [['equipment_item_id' => $item->id, 'qty' => 1]],
            // trainee/alumni extras optional but allowed
        ];

        $res = $this->withHeaders(['Origin' => 'https://learning.loveinarts.com'])->postJson('/api/v1/material-requests/submit', $payload);
        $res->assertStatus(201);

        // client should still be valid
        $payload2 = $payload;
        $payload2['requester_type'] = 'client';
        $payload2['email'] = 'c@example.com';
        $res2 = $this->withHeaders(['Origin' => 'https://learning.loveinarts.com'])->postJson('/api/v1/material-requests/submit', $payload2);
        $res2->assertStatus(201);
    }

    public function test_workflow_and_permissions_with_proof_photos(): void
    {
        Storage::fake('public');
        Mail::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $storekeeper = User::factory()->create(['role' => 'storekeeper']);
        $item = EquipmentItem::factory()->create(['total_qty' => 10]);

        config(['material-requests.allowed_origins' => ['https://learning.loveinarts.com']]);
        $submit = $this->withHeaders(['Origin' => 'https://learning.loveinarts.com'])->postJson('/api/v1/material-requests/submit', [
            'requester_type' => 'client',
            'full_name' => 'John',
            'phone' => '111',
            'email' => 'john@example.com',
            'emergency_contact_name' => 'EC',
            'emergency_contact_phone' => '222',
            'purpose_project_description' => 'Use',
            'needed_from' => now()->toDateString(),
            'return_by' => now()->addDays(2)->toDateString(),
            'terms_accepted' => true,
            'items' => [['equipment_item_id' => $item->id, 'qty' => 2]],
        ]);
        $submit->assertStatus(201);
        $ref = $submit->json('reference_code');
        $mr = MaterialRequest::query()->where('reference_code', $ref)->first();

        // approve
        $this->actingAs($admin)->postJson("/api/material-requests/{$mr->id}/approve", ['items' => [['id' => $mr->items->first()->id, 'qty_approved' => 2]]])->assertStatus(200);
        $mr->refresh();
        $this->assertEquals('approved', $mr->status);

        // issue requires proof photo
        $proof = UploadedFile::fake()->create('proof.jpg', 100, 'image/jpeg');
        $this->actingAs($storekeeper)->post("/api/material-requests/{$mr->id}/issue", [
            'items' => [
                ['id' => $mr->items->first()->id, 'qty_issued' => 2, 'issue_condition' => 'good'],
            ],
        ])->assertStatus(422); // missing file

        $resIssue = $this->actingAs($storekeeper)->post("/api/material-requests/{$mr->id}/issue", [
            'items' => [
                ['id' => $mr->items->first()->id, 'qty_issued' => 2, 'issue_condition' => 'good', 'issue_proof' => $proof],
            ],
        ]);
        $resIssue->assertStatus(200);
        $mr->refresh();
        $this->assertEquals('issued', $mr->status);

        // return requires proof photo
        $retProof = UploadedFile::fake()->create('ret.jpg', 100, 'image/jpeg');
        $resRet = $this->actingAs($storekeeper)->post("/api/material-requests/{$mr->id}/return", [
            'items' => [
                ['id' => $mr->items->first()->id, 'qty_returned' => 1, 'return_condition' => 'ok', 'return_proof' => $retProof],
            ],
        ]);
        $resRet->assertStatus(200);
        $mr->refresh();
        $this->assertEquals('returned', $mr->status); // partial

        // complete
        $retProof2 = UploadedFile::fake()->create('ret2.jpg', 100, 'image/jpeg');
        $resRet2 = $this->actingAs($storekeeper)->post("/api/material-requests/{$mr->id}/return", [
            'items' => [
                ['id' => $mr->items->first()->id, 'qty_returned' => 1, 'return_condition' => 'ok', 'return_proof' => $retProof2],
            ],
        ]);
        $resRet2->assertStatus(200);
        $mr->refresh();
        $this->assertEquals('closed', $mr->status);
    }
}
