<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\Cohort;
use App\Models\EquipmentItem;
use App\Models\MaterialRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The portal signs the trusted account email and must submit that same address
 * as the booking email. A different address typed on the form does not match.
 */
class PortalAlumniCertificatePayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['material-requests.alumni_certificate_required_for_api' => true]);
        Mail::fake();
        Http::fake();
    }

    public function test_proof_for_the_account_email_is_accepted_when_the_booking_email_matches(): void
    {
        $apiKey = 'portal-media-api-key';
        $accountEmail = 'portal.account@example.com';
        ApiClient::query()->create([
            'name' => 'LIA Learning Portal',
            'key_hash' => Hash::make($apiKey),
            'prefill_secret' => $apiKey,
            'active' => true,
            'allows_alumni_certificate_exempt' => true,
        ]);

        $this->withHeaders(['X-LIA-Key' => $apiKey])->postJson(
            '/api/v1/material-requests/submit',
            $this->portalPayload($apiKey, 17, $accountEmail, $accountEmail),
        )->assertCreated()
            ->assertJsonPath('message', 'Request submitted');

        $booking = MaterialRequest::query()->where('email', $accountEmail)->firstOrFail();
        $this->assertSame('grandfathered', $booking->certificate_source);
        $this->assertNull($booking->certificate_path);
    }

    public function test_typed_email_that_differs_from_the_account_email_is_rejected(): void
    {
        $apiKey = 'portal-media-api-key';
        $accountEmail = 'portal.account@example.com';
        $typedEmail = 'typed.on.form@example.com';
        ApiClient::query()->create([
            'name' => 'LIA Learning Portal',
            'key_hash' => Hash::make($apiKey),
            'prefill_secret' => $apiKey,
            'active' => true,
            'allows_alumni_certificate_exempt' => true,
        ]);

        $this->withHeaders(['X-LIA-Key' => $apiKey])->postJson(
            '/api/v1/material-requests/submit',
            $this->portalPayload($apiKey, 17, $accountEmail, $typedEmail),
        )->assertStatus(422)->assertJsonValidationErrors('certificate');

        $this->assertSame(0, MaterialRequest::query()->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function portalPayload(string $apiKey, int $portalUserId, string $accountEmail, string $bookingEmail): array
    {
        $item = EquipmentItem::factory()->create(['total_qty' => 3]);
        $cohort = Cohort::query()->firstOrCreate(['name' => 'Cohort 2'], ['code' => 'C2', 'year' => 2024]);
        $issuedAt = now()->getTimestamp();
        $signed = 'alumni-certificate-grandfathered|'.$portalUserId.'|'.$accountEmail.'|'.$issuedAt;

        return [
            'requester_type' => 'alumni',
            'full_name' => 'Portal Alumni',
            'phone' => '0788000111',
            'email' => $bookingEmail,
            'project_name' => 'Portal booking',
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
            'terms_accepted' => true,
            'consent_declaration' => true,
            'cohort_id' => $cohort->id,
            'signature' => 'data:image/png;base64,'.base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==')),
            'id_document' => 'data:application/pdf;base64,'.base64_encode('%PDF-1.4 id'),
            'items' => [['equipment_item_id' => $item->id, 'qty' => 1]],
            'alumni_certificate_exempt_user' => $portalUserId,
            'alumni_certificate_exempt_email' => $accountEmail,
            'alumni_certificate_exempt_at' => $issuedAt,
            'alumni_certificate_exempt_sig' => hash_hmac('sha256', $signed, $apiKey),
        ];
    }
}
