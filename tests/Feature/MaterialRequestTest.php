<?php

namespace Tests\Feature;

use App\Mail\MaterialRequestPrefillCodeMail;
use App\Models\ApiClient;
use App\Models\Cohort;
use App\Models\EquipmentItem;
use App\Models\MaterialRequest;
use App\Models\User;
use App\Services\MaterialRequestPrefillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MaterialRequestTest extends TestCase
{
    use RefreshDatabase;

    private function signature(): string
    {
        return 'data:image/png;base64,'.base64_encode('signature-png-bytes');
    }

    private function idDocument(): string
    {
        return 'data:image/png;base64,'.base64_encode('id-png-bytes');
    }

    private function payload(EquipmentItem $item, array $overrides = []): array
    {
        return array_merge([
            'requester_type' => 'client',
            'full_name' => 'Test User',
            'phone' => '0780000000',
            'email' => 't@example.com',
            'project_name' => 'My Project',
            'province_res' => 'East',
            'district_res' => 'Bugesera',
            'sector_res' => 'Gashora',
            'cell_res' => 'Biryogo',
            'village_res' => 'Bidudu',
            'emergency_contact_name' => 'EC',
            'emergency_contact_phone' => '0781111111',
            'relationship' => 'Sibling',
            'project_summary' => 'A project summary',
            'needed_from' => now()->toDateString(),
            'return_by' => now()->addDay()->toDateString(),
            'terms_accepted' => true,
            'consent_declaration' => true,
            'signature' => $this->signature(),
            'id_document' => $this->idDocument(),
            'items' => [['equipment_item_id' => $item->id, 'qty' => 1]],
        ], $overrides);
    }

    public function test_hosted_form_renders_bilingual_question_list(): void
    {
        $this->get('/request-materials?type=alumni')
            ->assertOk()
            ->assertSee('Full Names')
            ->assertSee('Amazina yose')
            ->assertSee('Project Name')
            ->assertSee('Upload Your ID')
            ->assertSee('Signature & Date')
            ->assertSee('rwanda-divisions.json');
    }

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

    public function test_type_specific_validation_and_required_id(): void
    {
        config(['material-requests.allowed_origins' => ['https://learning.loveinarts.com']]);
        $item = EquipmentItem::factory()->create(['total_qty' => 5]);
        $cohort = Cohort::query()->create(['name' => 'Cohort A', 'code' => 'COH-A', 'year' => 2026]);

        $trainee = $this->payload($item, [
            'requester_type' => 'trainee',
            'email' => 'trainee@example.com',
            'phone' => '0780000001',
            'cohort_id' => $cohort->id,
            'id_document' => null,
        ]);
        $this->withHeaders(['Origin' => 'https://learning.loveinarts.com'])->postJson('/api/v1/material-requests/submit', $trainee)->assertStatus(201);

        // A client needs an ID document.
        $client = $this->payload($item, ['email' => 'client@example.com', 'phone' => '0780000002', 'id_document' => null]);
        $this->withHeaders(['Origin' => 'https://learning.loveinarts.com'])->postJson('/api/v1/material-requests/submit', $client)
            ->assertStatus(422)->assertJsonValidationErrors('id_document');

        // Trainee/alumni need a cohort from the cohorts table.
        $missingCohort = $this->payload($item, ['requester_type' => 'alumni', 'email' => 'alumni@example.com', 'phone' => '0780000003']);
        $this->withHeaders(['Origin' => 'https://learning.loveinarts.com'])->postJson('/api/v1/material-requests/submit', $missingCohort)
            ->assertStatus(422)->assertJsonValidationErrors('cohort_id');

        // The return date must not be before the pick-up date.
        $badDates = $this->payload($item, ['email' => 'dates@example.com', 'phone' => '0780000004', 'return_by' => now()->subDay()->toDateString()]);
        $this->withHeaders(['Origin' => 'https://learning.loveinarts.com'])->postJson('/api/v1/material-requests/submit', $badDates)
            ->assertStatus(422)->assertJsonValidationErrors('return_by');
    }

    public function test_signature_date_project_location_copy_and_bilingual_config(): void
    {
        config(['material-requests.allowed_origins' => ['https://learning.loveinarts.com']]);
        $item = EquipmentItem::factory()->create(['total_qty' => 5]);
        $cohort = Cohort::query()->create(['name' => 'Alumni Cohort', 'code' => 'AL-1', 'year' => 2025]);

        $config = $this->withHeaders(['Origin' => 'https://learning.loveinarts.com'])->getJson('/api/v1/material-requests/config')->assertOk();
        $config->assertJsonPath('labels.full_name.en', 'Full Names')
            ->assertJsonPath('labels.full_name.kn', 'Amazina yose')
            ->assertJsonPath('labels.cohort_id.en', 'Select Cohort')
            ->assertJsonPath('cohorts.0.name', 'Alumni Cohort');

        $payload = $this->payload($item, [
            'requester_type' => 'alumni',
            'email' => 'alumni2@example.com',
            'phone' => '0780000005',
            'cohort_id' => $cohort->id,
            'project_same_as_residential' => true,
            'signature_date' => '2000-01-01',
        ]);
        $this->withHeaders(['Origin' => 'https://learning.loveinarts.com'])->postJson('/api/v1/material-requests/submit', $payload)->assertStatus(201);

        $request = MaterialRequest::query()->where('email', 'alumni2@example.com')->firstOrFail();
        $this->assertSame(now()->toDateString(), $request->signature_date?->toDateString());
        $this->assertSame('East', $request->project_province);
        $this->assertSame('Bugesera', $request->project_district);
        $this->assertSame('Gashora', $request->project_sector);
        $this->assertSame('Biryogo', $request->project_cell);
        $this->assertSame($cohort->id, $request->cohort_id);
        $this->assertTrue($request->consent_declaration);
    }

    public function test_signed_prefill_token_prefills_and_locks_fields(): void
    {
        $client = ApiClient::query()->create([
            'name' => 'Learning portal',
            'key_hash' => Hash::make('secret-key'),
            'prefill_secret' => 'secret-key',
            'allowed_origin' => 'https://learning.loveinarts.com',
            'active' => true,
        ]);
        $cohort = Cohort::query()->create(['name' => 'Cohort B', 'code' => 'COH-B', 'year' => 2026]);

        $token = app(MaterialRequestPrefillService::class)->issue($client, [
            'name' => 'Portal Student',
            'email' => 'student@example.com',
            'phone' => '0782222222',
            'type' => 'trainee',
            'cohort' => $cohort->code,
            'student_alumni_id' => 'STD-42',
        ]);

        $response = $this->getJson('/api/v1/material-requests/prefill?token='.urlencode($token))
            ->assertOk()
            ->assertJsonPath('fields.full_name', 'Portal Student')
            ->assertJsonPath('fields.email', 'student@example.com')
            ->assertJsonPath('fields.requester_type', 'trainee')
            ->assertJsonPath('fields.cohort_id', $cohort->id)
            ->assertJsonPath('fields.student_alumni_id', 'STD-42')
            ->assertJsonStructure(['fields', 'locked', 'id_reuse_token']);
        $this->assertContains('full_name', $response->json('locked'));
        $this->assertContains('cohort_id', $response->json('locked'));

        $this->getJson('/api/v1/material-requests/prefill?token='.urlencode($token.'x'))->assertStatus(422);
    }

    public function test_otp_start_does_not_reveal_whether_a_contact_exists(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/material-requests/prefill/start', ['email' => 'nobody@example.com'])
            ->assertOk()
            ->assertJsonPath('sent_to', 'n*****@example.com')
            ->assertJsonStructure(['challenge']);

        Mail::assertNothingQueued();
    }

    public function test_returning_requester_otp_prefills_private_details_and_reuses_id(): void
    {
        Mail::fake();
        config(['material-requests.allowed_origins' => ['https://learning.loveinarts.com']]);
        $item = EquipmentItem::factory()->create(['total_qty' => 5]);

        $first = $this->payload($item, ['email' => 'returning@example.com', 'phone' => '0783333333']);
        $this->withHeaders(['Origin' => 'https://learning.loveinarts.com'])->postJson('/api/v1/material-requests/submit', $first)->assertStatus(201);

        $start = $this->postJson('/api/v1/material-requests/prefill/start', [
            'email' => 'returning@example.com',
            'phone' => '0783333333',
        ])->assertOk();

        $challenge = $start->json('challenge');
        $code = null;
        Mail::assertQueued(MaterialRequestPrefillCodeMail::class, function (MaterialRequestPrefillCodeMail $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        $verify = $this->postJson('/api/v1/material-requests/prefill/verify', [
            'challenge' => $challenge,
            'code' => $code,
        ])->assertOk()
            ->assertJsonPath('fields.village_res', 'Bidudu')
            ->assertJsonPath('fields.emergency_contact_name', 'EC');

        $reuseToken = $verify->json('id_reuse_token');
        $this->assertNotEmpty($reuseToken);

        $second = $this->payload($item, [
            'email' => 'returning@example.com',
            'phone' => '0783333333',
            'id_document' => null,
            'reuse_id_token' => $reuseToken,
        ]);
        $this->withHeaders(['Origin' => 'https://learning.loveinarts.com'])->postJson('/api/v1/material-requests/submit', $second)->assertStatus(201);

        $reused = MaterialRequest::query()->where('email', 'returning@example.com')->latest('id')->firstOrFail();
        $this->assertNotNull($reused->id_document_path);
    }

    public function test_workflow_and_permissions_with_proof_photos(): void
    {
        Storage::fake('public');
        Mail::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $storekeeper = User::factory()->create(['role' => 'storekeeper']);
        $item = EquipmentItem::factory()->create(['total_qty' => 10]);

        config(['material-requests.allowed_origins' => ['https://learning.loveinarts.com']]);
        $submit = $this->withHeaders(['Origin' => 'https://learning.loveinarts.com'])->postJson('/api/v1/material-requests/submit', $this->payload($item, [
            'email' => 'john@example.com',
            'phone' => '0784444444',
            'items' => [['equipment_item_id' => $item->id, 'qty' => 2]],
        ]));
        $submit->assertStatus(201);
        $ref = $submit->json('reference_code');
        $mr = MaterialRequest::query()->where('reference_code', $ref)->firstOrFail();

        $viewer = User::factory()->create(['role' => 'communications']);
        $this->actingAs($viewer)->getJson("/api/material-requests/{$mr->id}")
            ->assertOk()
            ->assertJsonPath('request.id_document_name', 'id-document.png');
        $this->getJson("/api/material-requests/{$mr->id}/id-document")->assertOk()->assertJsonStructure(['url']);

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
        ])->assertStatus(422);

        $this->actingAs($storekeeper)->post("/api/material-requests/{$mr->id}/issue", [
            'items' => [
                ['id' => $mr->items->first()->id, 'qty_issued' => 2, 'issue_condition' => 'good', 'issue_proof' => $proof],
            ],
        ])->assertStatus(200);
        $mr->refresh();
        $this->assertEquals('issued', $mr->status);

        // return requires proof photo
        $retProof = UploadedFile::fake()->create('ret.jpg', 100, 'image/jpeg');
        $this->actingAs($storekeeper)->post("/api/material-requests/{$mr->id}/return", [
            'items' => [
                ['id' => $mr->items->first()->id, 'qty_returned' => 1, 'return_condition' => 'ok', 'return_proof' => $retProof],
            ],
        ])->assertStatus(200);
        $mr->refresh();
        $this->assertEquals('returned', $mr->status); // partial

        // complete
        $retProof2 = UploadedFile::fake()->create('ret2.jpg', 100, 'image/jpeg');
        $this->actingAs($storekeeper)->post("/api/material-requests/{$mr->id}/return", [
            'items' => [
                ['id' => $mr->items->first()->id, 'qty_returned' => 1, 'return_condition' => 'ok', 'return_proof' => $retProof2],
            ],
        ])->assertStatus(200);
        $mr->refresh();
        $this->assertEquals('closed', $mr->status);
    }
}
