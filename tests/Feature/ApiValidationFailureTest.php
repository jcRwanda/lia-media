<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\Cohort;
use App\Models\EquipmentItem;
use App\Models\MaterialRequest;
use App\Support\ApiValidationFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiValidationFailureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<MessageLogged>
     */
    private array $validationLogs = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['material-requests.alumni_certificate_required_for_api' => true]);
        $this->validationLogs = [];
        Log::listen(function (MessageLogged $event): void {
            if ($event->message === ApiValidationFailure::LOG_MESSAGE) {
                $this->validationLogs[] = $event;
            }
        });
    }

    public function test_submit_validation_logs_field_names_and_correlation_id_without_personal_values(): void
    {
        $apiKey = 'sentinel-api-key-value';
        $email = 'sentinel-person@example.com';
        $name = 'SentinelPersonName';
        $phone = '0788555000';
        $certificate = 'sentinel-certificate-bytes';
        $signature = 'sentinel-exempt-signature';
        ApiClient::query()->create([
            'name' => 'Alumni Portal',
            'key_hash' => Hash::make($apiKey),
            'prefill_secret' => $apiKey,
            'active' => true,
            'allows_alumni_certificate_exempt' => false,
        ]);

        $missingName = $this->withHeaders(['X-LIA-Key' => $apiKey])->postJson(
            '/api/v1/material-requests/submit',
            $this->payload([
                'full_name' => null,
                'email' => $email,
                'phone' => $phone,
                'certificate' => 'data:application/pdf;base64,'.base64_encode($certificate),
                'alumni_certificate_exempt_sig' => $signature,
            ]),
        )->assertStatus(422);

        $missingName->assertJsonValidationErrors('full_name');
        $this->assertLoggedFailure($missingName->json('correlation_id'), ['full_name'], 'api/v1/material-requests/submit');

        $missingCertificate = $this->withHeaders(['X-LIA-Key' => $apiKey])->postJson(
            '/api/v1/material-requests/submit',
            $this->payload([
                'full_name' => $name,
                'email' => $email,
                'phone' => $phone,
                'alumni_certificate_exempt_user' => '42',
                'alumni_certificate_exempt_email' => $email,
                'alumni_certificate_exempt_at' => now()->getTimestamp(),
                'alumni_certificate_exempt_sig' => $signature,
            ]),
        )->assertStatus(422);

        $missingCertificate->assertJsonValidationErrors('certificate');
        $this->assertLoggedFailure($missingCertificate->json('correlation_id'), ['certificate'], 'api/v1/material-requests/submit');
        $this->assertSame(0, MaterialRequest::query()->count());

        $logged = json_encode($this->validationLogs, JSON_THROW_ON_ERROR);
        foreach ([$apiKey, $email, $name, $phone, $certificate, $signature] as $secret) {
            $this->assertStringNotContainsString($secret, $logged);
        }
    }

    public function test_logged_context_drops_unsafe_field_names_and_message_values(): void
    {
        $sentinel = 'sentinel-secret-value-not-for-logs';
        $request = Request::create('/api/v1/material-requests/submit', 'POST', [
            'email' => $sentinel,
            'certificate' => $sentinel,
        ]);

        $response = app(ApiValidationFailure::class)->response($request, [
            'email' => ["Address {$sentinel} is not allowed."],
            $sentinel => ['rejected'],
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $body = $response->getContent();
        $this->assertStringContainsString($sentinel, $body);
        $this->assertTrue(Str::isUuid($response->getData(true)['correlation_id']));

        $this->assertCount(1, $this->validationLogs);
        $context = $this->validationLogs[0]->context;
        $this->assertSame(['email', 'redacted_field'], $context['fields']);
        $this->assertSame(422, $context['status']);
        $this->assertSame(
            ['correlation_id', 'status', 'fields', 'method', 'route'],
            array_keys($context),
        );
        $encoded = json_encode($this->validationLogs[0], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($sentinel, $encoded);
    }

    /**
     * @param  list<string>  $fields
     */
    private function assertLoggedFailure(mixed $correlationId, array $fields, string $route): void
    {
        $this->assertTrue(is_string($correlationId) && Str::isUuid($correlationId));
        $match = null;
        foreach ($this->validationLogs as $event) {
            if (($event->context['correlation_id'] ?? null) === $correlationId) {
                $match = $event;
                break;
            }
        }
        $this->assertNotNull($match);
        $this->assertSame('warning', $match->level);
        $this->assertSame([
            'correlation_id' => $correlationId,
            'status' => 422,
            'fields' => $fields,
            'method' => 'POST',
            'route' => $route,
        ], $match->context);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        $item = EquipmentItem::factory()->create(['total_qty' => 3]);
        $cohort = Cohort::query()->firstOrCreate(['name' => 'Cohort 2'], ['code' => 'C2', 'year' => 2024]);

        $payload = [
            'requester_type' => 'alumni',
            'full_name' => 'Grace Alumna',
            'phone' => '0788000111',
            'email' => 'grace@example.com',
            'project_name' => 'Doc',
            'national_id' => '1199880012345678',
            'province_res' => 'Kigali',
            'district_res' => 'Gasabo',
            'sector_res' => 'Remera',
            'cell_res' => 'Rukiri',
            'village_res' => 'Amahoro',
            'emergency_contact_name' => 'Mum',
            'emergency_contact_phone' => '0788000222',
            'relationship' => 'Mother',
            'purpose_project_description' => 'Shoot',
            'project_province' => 'Kigali',
            'project_district' => 'Gasabo',
            'project_sector' => 'Remera',
            'project_cell' => 'Rukiri',
            'project_village' => 'Amahoro',
            'needed_from' => now()->addDays(3)->toDateString(),
            'return_by' => now()->addDays(5)->toDateString(),
            'terms_accepted' => '1',
            'consent_declaration' => '1',
            'cohort_id' => $cohort->id,
            'signature' => 'data:image/png;base64,'.base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==')),
            'id_document' => 'data:application/pdf;base64,'.base64_encode('%PDF-1.4 id'),
            'items' => [['equipment_item_id' => $item->id, 'qty' => 1]],
        ];

        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($payload[$key]);
            } else {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }
}
