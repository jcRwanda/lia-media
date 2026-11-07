<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\Cohort;
use App\Models\EquipmentItem;
use App\Models\MaterialRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AlumniCertificateGrandfatherTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-07 12:00:00 UTC'));
        config(['material-requests.alumni_certificate_required_for_api' => true]);
        Mail::fake();
        Http::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_grandfathered_alumni_booking_is_accepted_without_a_certificate(): void
    {
        $secret = 'portal-api-key';
        $client = $this->client('Alumni Portal', $secret, $secret, true);
        $email = 'Alum@Example.com';

        $response = $this->withHeaders(['X-LIA-Key' => $secret])->postJson(
            '/api/v1/material-requests/submit',
            $this->submitPayload(array_merge(
                ['email' => ' alum@example.com '],
                $this->proof($secret, '42', $email, now()->getTimestamp()),
            )),
        )->assertCreated();

        $response->assertJsonPath(
            'alumni_certificate_reminder',
            'Booking accepted. A graduation certificate is not required for this grandfathered alumni account and does not block the request.',
        );
        $booking = MaterialRequest::query()->where('email', 'alum@example.com')->firstOrFail();
        $this->assertSame($client->id, $booking->api_client_id);
        $this->assertSame('grandfathered', $booking->certificate_source);
        $this->assertNull($booking->certificate_path);
        $this->assertNull($booking->certificate_url);

        $this->withHeaders(['X-LIA-Key' => $secret])->postJson(
            '/api/v1/material-requests/submit',
            $this->submitPayload(['email' => 'alum@example.com']),
        )->assertStatus(422)->assertJsonValidationErrors('certificate');

        $prefixed = $this->proof($secret, '42', 'other@example.com', now()->getTimestamp());
        $prefixed['alumni_certificate_exempt_sig'] = 'sha256='.strtoupper($prefixed['alumni_certificate_exempt_sig']);
        $this->withHeaders(['X-LIA-Key' => $secret])->postJson(
            '/api/v1/material-requests/submit',
            $this->submitPayload(array_merge(['email' => 'other@example.com'], $prefixed)),
        )->assertCreated();
        $this->assertSame(
            'grandfathered',
            MaterialRequest::query()->where('email', 'other@example.com')->firstOrFail()->certificate_source,
        );
    }

    public function test_new_alumni_without_certificate_or_proof_is_rejected(): void
    {
        $secret = 'portal-api-key';
        $this->client('Alumni Portal', $secret, $secret, true);

        $this->withHeaders(['X-LIA-Key' => $secret])->postJson(
            '/api/v1/material-requests/submit',
            $this->submitPayload(['email' => 'new@example.com']),
        )->assertStatus(422)->assertJsonValidationErrors('certificate');

        $this->assertSame(0, MaterialRequest::query()->count());
    }

    public function test_uploaded_certificate_is_stored_and_reused(): void
    {
        Storage::fake(config('filesystems.default'));
        $secret = 'portal-api-key';
        $this->client('Alumni Portal', $secret, $secret, true);
        $headers = ['X-LIA-Key' => $secret];
        $certificate = 'data:application/pdf;base64,'.base64_encode('%PDF-1.4 cert');

        $this->withHeaders($headers)->postJson('/api/v1/material-requests/submit', $this->submitPayload([
            'email' => 'stored@example.com',
            'certificate' => $certificate,
        ]))->assertCreated();
        $first = MaterialRequest::query()->where('email', 'stored@example.com')->firstOrFail();
        $this->assertSame('uploaded', $first->certificate_source);
        $this->assertStringStartsWith('material-requests/certificates/', $first->certificate_path);

        $this->withHeaders($headers)->postJson('/api/v1/material-requests/submit', $this->submitPayload([
            'email' => 'stored@example.com',
        ]))->assertCreated()->assertJsonMissingPath('alumni_certificate_reminder');
        $second = MaterialRequest::query()->where('email', 'stored@example.com')->latest('id')->firstOrFail();
        $this->assertSame('reused', $second->certificate_source);
        $this->assertSame($first->certificate_path, $second->certificate_path);
    }

    public function test_forged_proof_is_rejected(): void
    {
        $secret = 'portal-api-key';
        $this->client('Alumni Portal', $secret, $secret, true);
        $proof = $this->proof($secret, '42', 'alum@example.com', now()->getTimestamp());
        $proof['alumni_certificate_exempt_sig'] = substr($proof['alumni_certificate_exempt_sig'], 0, -1)
            .($proof['alumni_certificate_exempt_sig'][-1] === '0' ? '1' : '0');

        $this->withHeaders(['X-LIA-Key' => $secret])->postJson(
            '/api/v1/material-requests/submit',
            $this->submitPayload(array_merge(['email' => 'alum@example.com'], $proof)),
        )->assertStatus(422)->assertJsonValidationErrors('certificate');
        $this->assertSame(0, MaterialRequest::query()->count());
    }

    public function test_expired_and_future_proofs_are_rejected(): void
    {
        $secret = 'portal-api-key';
        $this->client('Alumni Portal', $secret, $secret, true);
        $now = now()->getTimestamp();
        $headers = ['X-LIA-Key' => $secret];

        $this->withHeaders($headers)->postJson('/api/v1/material-requests/submit', $this->submitPayload(array_merge(
            ['email' => 'edge@example.com'],
            $this->proof($secret, '7', 'edge@example.com', $now - 600),
        )))->assertCreated();

        $this->withHeaders($headers)->postJson('/api/v1/material-requests/submit', $this->submitPayload(array_merge(
            ['email' => 'expired@example.com'],
            $this->proof($secret, '7', 'expired@example.com', $now - 601),
        )))->assertStatus(422)->assertJsonValidationErrors('certificate');

        $this->withHeaders($headers)->postJson('/api/v1/material-requests/submit', $this->submitPayload(array_merge(
            ['email' => 'skew@example.com'],
            $this->proof($secret, '7', 'skew@example.com', $now + 30),
        )))->assertCreated();

        $this->withHeaders($headers)->postJson('/api/v1/material-requests/submit', $this->submitPayload(array_merge(
            ['email' => 'future@example.com'],
            $this->proof($secret, '7', 'future@example.com', $now + 31),
        )))->assertStatus(422)->assertJsonValidationErrors('certificate');

        $this->assertNull(MaterialRequest::query()->where('email', 'future@example.com')->first());
        $this->assertNull(MaterialRequest::query()->where('email', 'expired@example.com')->first());
    }

    public function test_proof_email_must_match_the_booking_email(): void
    {
        $secret = 'portal-api-key';
        $this->client('Alumni Portal', $secret, $secret, true);

        $this->withHeaders(['X-LIA-Key' => $secret])->postJson('/api/v1/material-requests/submit', $this->submitPayload(array_merge(
            ['email' => 'booking@example.com'],
            $this->proof($secret, '42', 'account@example.com', now()->getTimestamp()),
        )))->assertStatus(422)->assertJsonValidationErrors('certificate');

        $this->assertSame(0, MaterialRequest::query()->count());
    }

    public function test_hosted_form_and_bare_boolean_cannot_bypass(): void
    {
        $secret = 'portal-api-key';
        $this->client('Alumni Portal', $secret, $secret, true);
        config(['material-requests.allowed_origins' => ['https://loveinarts.com']]);
        $proof = $this->proof($secret, '42', 'public@example.com', now()->getTimestamp());

        $this->withHeaders(['sec-fetch-site' => 'same-origin'])->postJson('/api/v1/material-requests/submit', $this->submitPayload(array_merge(
            ['email' => 'public@example.com'],
            $proof,
            ['alumni_certificate_exempt' => true],
        )))->assertStatus(422)->assertJsonValidationErrors('certificate');

        $this->withHeaders(['Origin' => 'https://loveinarts.com'])->postJson('/api/v1/material-requests/submit', $this->submitPayload(array_merge(
            ['email' => 'browser@example.com'],
            $this->proof($secret, '42', 'browser@example.com', now()->getTimestamp()),
        )))->assertStatus(422)->assertJsonValidationErrors('certificate');

        $this->withHeaders(['X-LIA-Key' => $secret])->postJson('/api/v1/material-requests/submit', $this->submitPayload([
            'email' => 'bool@example.com',
            'alumni_certificate_exempt' => true,
            'alumni_certificate_grandfathered' => true,
        ]))->assertStatus(422)->assertJsonValidationErrors('certificate');

        $this->assertSame(0, MaterialRequest::query()->count());
    }

    public function test_another_api_client_cannot_exempt(): void
    {
        $portalSecret = 'portal-api-key';
        $otherSecret = 'other-client-key';
        $this->client('Alumni Portal', $portalSecret, $portalSecret, true);
        $other = $this->client('School Portal', $otherSecret, $otherSecret, false);
        $this->assertFalse($other->allows_alumni_certificate_exempt);

        $ownProof = $this->proof($otherSecret, '9', 'other@example.com', now()->getTimestamp());
        $this->withHeaders(['X-LIA-Key' => $otherSecret])->postJson('/api/v1/material-requests/submit', $this->submitPayload(array_merge(
            ['email' => 'other@example.com'],
            $ownProof,
        )))->assertStatus(422)->assertJsonValidationErrors('certificate');

        $stolen = $this->proof($portalSecret, '9', 'stolen@example.com', now()->getTimestamp());
        $this->withHeaders(['X-LIA-Key' => $otherSecret])->postJson('/api/v1/material-requests/submit', $this->submitPayload(array_merge(
            ['email' => 'stolen@example.com'],
            $stolen,
        )))->assertStatus(422)->assertJsonValidationErrors('certificate');

        $this->assertSame(0, MaterialRequest::query()->count());
    }

    public function test_prefill_secret_match_is_a_boolean_and_only_that_secret_verifies(): void
    {
        $apiKey = 'api-key-do-not-print';
        $prefill = 'prefill-value-do-not-print';
        $client = $this->client('Alumni Portal', $apiKey, $prefill, true);
        $this->assertFalse($client->fresh()->prefillSecretMatchesApiKey());

        $this->withHeaders(['X-LIA-Key' => $apiKey])->postJson('/api/v1/material-requests/submit', $this->submitPayload(array_merge(
            ['email' => 'signed-with-key@example.com'],
            $this->proof($apiKey, '3', 'signed-with-key@example.com', now()->getTimestamp()),
        )))->assertStatus(422)->assertJsonValidationErrors('certificate');

        $accepted = $this->withHeaders(['X-LIA-Key' => $apiKey])->postJson('/api/v1/material-requests/submit', $this->submitPayload(array_merge(
            ['email' => 'signed-with-secret@example.com'],
            $this->proof($prefill, '3', 'signed-with-secret@example.com', now()->getTimestamp()),
        )))->assertCreated();
        $this->assertStringNotContainsString($apiKey, $accepted->getContent());
        $this->assertStringNotContainsString($prefill, $accepted->getContent());

        $matching = $this->client('Matching Portal', 'same-value-do-not-print', 'same-value-do-not-print', false);
        $this->assertTrue($matching->fresh()->prefillSecretMatchesApiKey());

        $missingKey = 'missing-key-do-not-print';
        $missing = $this->client('No Secret Portal', $missingKey, null, true);
        $this->assertFalse($missing->fresh()->prefillSecretMatchesApiKey());
        $this->withHeaders(['X-LIA-Key' => $missingKey])->postJson('/api/v1/material-requests/submit', $this->submitPayload(array_merge(
            ['email' => 'nosecret@example.com'],
            $this->proof('anything', '3', 'nosecret@example.com', now()->getTimestamp()),
        )))->assertStatus(422)->assertJsonValidationErrors('certificate');

        Artisan::call('material-requests:alumni-certificate-exempt', ['client' => (string) $client->id]);
        $output = Artisan::output();
        $this->assertStringContainsString('prefill_secret_matches_api_key=no', $output);
        $this->assertStringContainsString('exempt=yes', $output);
        $this->assertStringNotContainsString($apiKey, $output);
        $this->assertStringNotContainsString($prefill, $output);

        Artisan::call('material-requests:alumni-certificate-exempt', [
            'client' => (string) $matching->id,
            '--enable' => true,
        ]);
        $enabled = Artisan::output();
        $this->assertStringContainsString('prefill_secret_matches_api_key=yes', $enabled);
        $this->assertStringContainsString('exempt=yes', $enabled);
        $this->assertStringNotContainsString('same-value-do-not-print', $enabled);
        $this->assertTrue($matching->fresh()->allows_alumni_certificate_exempt);
    }

    private function client(string $name, string $apiKey, ?string $prefillSecret, bool $exempt): ApiClient
    {
        return ApiClient::query()->create([
            'name' => $name,
            'key_hash' => Hash::make($apiKey),
            'prefill_secret' => $prefillSecret,
            'active' => true,
            'allows_alumni_certificate_exempt' => $exempt,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function proof(string $secret, string $userId, string $email, int $issuedAt): array
    {
        $payload = 'alumni-certificate-grandfathered|'.$userId.'|'.$email.'|'.$issuedAt;

        return [
            'alumni_certificate_exempt_user' => $userId,
            'alumni_certificate_exempt_email' => $email,
            'alumni_certificate_exempt_at' => $issuedAt,
            'alumni_certificate_exempt_sig' => hash_hmac('sha256', $payload, $secret),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function submitPayload(array $overrides = []): array
    {
        $item = EquipmentItem::factory()->create(['total_qty' => 3]);
        $cohort = Cohort::query()->firstOrCreate(['name' => 'Cohort 2'], ['code' => 'C2', 'year' => 2024]);

        return array_merge([
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
        ], $overrides);
    }
}
