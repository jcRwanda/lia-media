<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\EquipmentBlock;
use App\Models\EquipmentItem;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Models\User;
use App\Services\MaterialRequestWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MaterialRequestPlatformApiTest extends TestCase
{
    use RefreshDatabase;

    private function client(array $overrides = []): ApiClient
    {
        return ApiClient::query()->create(array_merge([
            'name' => 'Learning Portal',
            'key_hash' => Hash::make('platform-key'),
            'prefill_secret' => 'platform-secret',
            'active' => true,
        ], $overrides));
    }

    private function headers(ApiClient $client): array
    {
        return ['X-LIA-Key' => 'platform-key'];
    }

    private function payload(EquipmentItem $item, array $overrides = []): array
    {
        return array_merge([
            'requester_type' => 'client',
            'full_name' => 'Platform User',
            'phone' => '0788888888',
            'email' => 'platform@example.com',
            'project_name' => 'Platform Project',
            'emergency_contact_name' => 'Emergency',
            'emergency_contact_phone' => '0789999999',
            'relationship' => 'Sibling',
            'project_summary' => 'A platform project',
            'needed_from' => now()->addDay()->toDateString(),
            'return_by' => now()->addDays(2)->toDateString(),
            'terms_accepted' => true,
            'consent_declaration' => true,
            'signature' => 'data:image/png;base64,'.base64_encode('signature'),
            'id_document' => 'data:image/png;base64,'.base64_encode('id'),
            'items' => [['equipment_item_id' => $item->id, 'qty' => 1]],
        ], $overrides);
    }

    private function existingRequest(EquipmentItem $item, ApiClient $client, array $overrides = []): MaterialRequest
    {
        $request = MaterialRequest::query()->create(array_merge([
            'api_client_id' => $client->id,
            'source' => $client->name,
            'requester_type' => 'client',
            'full_name' => 'Existing User',
            'phone' => '0787777777',
            'email' => 'existing@example.com',
            'project_name' => 'Existing',
            'needed_from' => now()->addDay()->toDateString(),
            'return_by' => now()->addDays(2)->toDateString(),
            'status' => 'approved',
        ], $overrides));

        MaterialRequestItem::query()->create([
            'material_request_id' => $request->id,
            'equipment_item_id' => $item->id,
            'qty_requested' => 3,
            'qty_approved' => 3,
            'status' => 'approved',
        ]);

        return $request;
    }

    public function test_form_schema_requires_a_client_key_and_returns_bilingual_fields(): void
    {
        $this->client();

        $this->getJson('/api/v1/material-requests/form-schema?type=alumni')->assertStatus(401);

        $response = $this->withHeaders(['X-LIA-Key' => 'platform-key'])
            ->getJson('/api/v1/material-requests/form-schema?type=alumni')
            ->assertOk()
            ->assertJsonPath('type', 'alumni')
            ->assertJsonPath('links.openapi', url('/api/v1/material-requests/openapi.json'));

        $fields = collect($response->json('fields'))->keyBy('key');
        $this->assertTrue($fields['cohort_id']['required']);
        $this->assertTrue($fields['id_document']['required']);
        $this->assertSame('Amazina yose', $fields['full_name']['label']['kn']);

        $client = $this->withHeaders(['X-LIA-Key' => 'platform-key'])
            ->getJson('/api/v1/material-requests/form-schema?type=client')
            ->assertOk()
            ->json();

        $clientFields = collect($client['fields'])->keyBy('key');
        $this->assertFalse($clientFields['cohort_id']['required']);
    }

    public function test_openapi_is_public_and_lists_platform_paths(): void
    {
        $this->getJson('/api/v1/material-requests/openapi.json')
            ->assertOk()
            ->assertJsonPath('openapi', '3.1.0')
            ->assertJsonStructure(['paths' => [
                '/api/v1/material-requests/form-schema',
                '/api/v1/material-requests/catalog',
                '/api/v1/material-requests/availability',
                '/api/v1/material-requests/submit',
                '/api/v1/material-requests/{reference}',
            ]]);
    }

    public function test_availability_reports_reserved_and_blocked_dates(): void
    {
        $client = $this->client();
        $item = EquipmentItem::factory()->create(['total_qty' => 5]);
        $this->existingRequest($item, $client);
        EquipmentBlock::factory()->create([
            'starts_on' => now()->addDays(3)->toDateString(),
            'ends_on' => now()->addDays(3)->toDateString(),
            'reason' => 'Maintenance',
        ]);

        $response = $this->withHeaders($this->headers($client))->getJson('/api/v1/material-requests/availability?'.http_build_query([
            'item_ids' => [$item->id],
            'from' => now()->addDay()->toDateString(),
            'to' => now()->addDays(3)->toDateString(),
        ]))->assertOk();

        $this->assertContains(now()->addDay()->toDateString(), $response->json('items.0.booked_dates'));
        $this->assertContains(now()->addDays(3)->toDateString(), $response->json('items.0.blocked_dates'));
        $this->assertSame(2, $response->json('items.0.daily.'.now()->addDay()->toDateString().'.available_qty'));
    }

    public function test_submission_source_and_stock_overlap_rejection(): void
    {
        $client = $this->client();
        $item = EquipmentItem::factory()->create(['total_qty' => 5]);

        $this->withHeaders($this->headers($client))->postJson('/api/v1/material-requests/submit', $this->payload($item, [
            'source_detail' => 'Teacher booking',
        ]))->assertCreated();

        $request = MaterialRequest::query()->where('email', 'platform@example.com')->firstOrFail();
        $this->assertSame($client->id, $request->api_client_id);
        $this->assertSame($client->name, $request->source);
        $this->assertSame('Teacher booking', $request->source_detail);

        $this->existingRequest($item, $client);

        $this->withHeaders($this->headers($client))->postJson('/api/v1/material-requests/submit', $this->payload($item, [
            'email' => 'overlap@example.com',
            'phone' => '0786666666',
            'items' => [['equipment_item_id' => $item->id, 'qty' => 5]],
        ]))->assertStatus(422)->assertJsonValidationErrors('items.0.qty');
    }

    public function test_global_blocked_dates_reject_submission(): void
    {
        $client = $this->client();
        $item = EquipmentItem::factory()->create(['total_qty' => 5]);
        EquipmentBlock::factory()->create([
            'starts_on' => now()->addDay()->toDateString(),
            'ends_on' => now()->addDays(2)->toDateString(),
            'reason' => 'Public holiday',
        ]);

        $this->withHeaders($this->headers($client))->postJson('/api/v1/material-requests/submit', $this->payload($item))
            ->assertStatus(422)
            ->assertJsonValidationErrors('needed_from');
    }

    public function test_reference_status_is_scoped_to_the_creating_client(): void
    {
        $client = $this->client();
        $other = $this->client([
            'name' => 'Other Portal',
            'key_hash' => Hash::make('other-key'),
        ]);
        $item = EquipmentItem::factory()->create(['total_qty' => 5]);
        $request = $this->existingRequest($item, $client, ['status' => 'submitted']);

        $this->withHeaders($this->headers($client))
            ->getJson('/api/v1/material-requests/'.$request->reference_code)
            ->assertOk()
            ->assertJsonPath('request.source', $client->name)
            ->assertJsonPath('request.status', 'submitted');

        $this->withHeaders(['X-LIA-Key' => 'other-key'])
            ->getJson('/api/v1/material-requests/'.$request->reference_code)
            ->assertNotFound();

        $this->flushHeaders();
        $this->getJson('/api/v1/material-requests/'.$request->reference_code)->assertStatus(401);
    }

    public function test_staff_source_tabs_and_filter_include_clients_and_hosted_form(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $client = $this->client();
        $other = $this->client(['name' => 'School Portal']);
        $item = EquipmentItem::factory()->create(['total_qty' => 5]);
        $this->existingRequest($item, $client, ['status' => 'submitted']);
        $this->existingRequest($item, $client, ['status' => 'submitted', 'email' => 'second@example.com']);
        $this->existingRequest($item, $other, ['status' => 'submitted']);
        $this->existingRequest($item, $client, [
            'source' => 'Direct/Hosted form',
            'api_client_id' => null,
            'status' => 'submitted',
        ]);
        $this->existingRequest($item, $client, [
            'source' => null,
            'api_client_id' => null,
            'status' => 'submitted',
        ]);

        $response = $this->actingAs($admin)->getJson('/api/material-requests')->assertOk();
        $response->assertJsonFragment(['key' => 'Learning Portal', 'count' => 2]);
        $response->assertJsonFragment(['key' => 'School Portal', 'count' => 1]);
        $response->assertJsonFragment(['key' => 'Direct/Hosted form', 'count' => 2]);

        $filtered = $this->actingAs($admin)->getJson('/api/material-requests?source=Learning%20Portal')->assertOk();
        $this->assertCount(2, $filtered->json('data'));
        $this->assertSame('Learning Portal', $filtered->json('data.0.source'));

        $direct = $this->actingAs($admin)->getJson('/api/material-requests?source=Direct%2FHosted%20form')->assertOk();
        $this->assertCount(2, $direct->json('data'));
    }

    public function test_webhook_payload_is_signed_with_the_client_secret(): void
    {
        Http::fake();

        $client = $this->client(['webhook_url' => 'https://portal.example.com/hooks/lia']);
        $item = EquipmentItem::factory()->create(['total_qty' => 5]);
        $request = $this->existingRequest($item, $client);

        app(MaterialRequestWebhookService::class)->send($request, 'submitted');

        Http::assertSent(function ($httpRequest) use ($client, $request) {
            $expected = 'sha256='.hash_hmac('sha256', $httpRequest->body(), 'platform-secret');

            return $httpRequest->url() === $client->webhook_url
                && $httpRequest->hasHeader('X-LIA-Signature', $expected)
                && $httpRequest->hasHeader('X-LIA-Request', $request->reference_code)
                && $httpRequest->data()['reference_code'] === $request->reference_code
                && $httpRequest->data()['status'] === 'approved';
        });
    }

    public function test_calendar_and_booking_rules_are_available_to_staff(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $client = $this->client();
        $item = EquipmentItem::factory()->create(['total_qty' => 5]);
        $this->existingRequest($item, $client);

        $this->actingAs($admin)->getJson('/api/equipment-calendar?month='.now()->addDay()->format('Y-m'))
            ->assertOk()
            ->assertJsonPath('items.0.id', $item->id)
            ->assertJsonPath('bookings.0.reference_code', MaterialRequest::query()->firstOrFail()->reference_code)
            ->assertJsonPath('rules.max_booking_days', 30);

        $this->actingAs($admin)->patchJson('/api/equipment-calendar/rules', [
            'closed_weekdays' => [0],
            'max_booking_days' => 14,
            'lead_time_days' => 2,
        ])->assertOk()
            ->assertJsonPath('rules.closed_weekdays.0', 0)
            ->assertJsonPath('rules.max_booking_days', 14)
            ->assertJsonPath('rules.lead_time_days', 2);

        $this->actingAs($admin)->postJson('/api/equipment-blocks', [
            'equipment_item_id' => $item->id,
            'starts_on' => now()->addDays(4)->toDateString(),
            'ends_on' => now()->addDays(4)->toDateString(),
            'reason' => 'Servicing',
        ])->assertCreated()->assertJsonPath('block.reason', 'Servicing');
    }
}
