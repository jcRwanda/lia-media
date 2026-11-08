<?php

namespace Tests\Feature;

use App\Mail\MaterialRequestStatusMail;
use App\Models\ApiClient;
use App\Models\EquipmentItem;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Models\User;
use App\Services\MaterialRequestDecisionService;
use App\Services\MaterialRequestWebhookService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BorrowerStorekeeperContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_borrower_whatsapp_exists_only_for_approved_and_issued_and_uses_the_storekeeper_number(): void
    {
        Mail::fake();
        Http::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $storekeeper = User::factory()->create([
            'role' => 'storekeeper',
            'name' => 'Jean Store',
            'phone' => '0781 111 222',
            'status' => 'active',
        ]);
        $item = EquipmentItem::factory()->create(['total_qty' => 5]);
        $mr = $this->request($item, '0788999000');
        $client = ApiClient::query()->create([
            'name' => 'Portal',
            'key_hash' => Hash::make('k'),
            'prefill_secret' => 's',
            'active' => true,
            'webhook_url' => 'https://portal.example.com/hook',
        ]);
        $mr->update(['api_client_id' => $client->id]);

        foreach (['submitted', 'changes_requested', 'rejected', 'returned', 'closed'] as $status) {
            $mr->update(['status' => $status]);
            $this->assertContactHidden($mr->fresh(), '250781111222');
        }

        $mr->update(['status' => 'approved']);
        $shown = $this->assertContactShown($mr->fresh(), '250781111222', 'Jean Store');
        $this->assertStringNotContainsString('0788999000', $shown->json('request.decision.storekeeper.whatsapp_url'));
        $this->assertStringNotContainsString('250789999000', (string) $shown->getContent());

        app(MaterialRequestWebhookService::class)->send($mr->fresh(), 'submitted');
        Http::assertSent(function ($request) {
            $decision = $request->data()['decision'] ?? [];

            return ($decision['status'] ?? null) === 'approved'
                && str_starts_with($decision['storekeeper']['whatsapp_url'] ?? '', 'https://wa.me/250781111222?text=')
                && ! str_contains(json_encode($decision), '0788999000');
        });

        $mr->update(['status' => 'issued']);
        $this->assertContactShown($mr->fresh(), '250781111222', 'Jean Store');

        app(SettingsService::class)->put([
            'storekeeper_whatsapp' => '+250 722 000 111',
            'storekeeper_contact_name' => 'Store desk',
        ]);
        $override = $this->assertContactShown($mr->fresh(), '250722000111', 'Store desk');
        $this->assertStringNotContainsString('250781111222', $override->json('request.decision.storekeeper.whatsapp_url'));

        $pending = $this->request($item, '0788999001');
        $pending->update(['api_client_id' => $client->id]);
        $this->actingAs($admin)->postJson("/api/material-requests/{$pending->id}/request-changes", ['note' => 'Shorten the dates'])->assertOk();
        $this->assertContactHidden($pending->fresh(), '250722000111');
        Mail::assertQueued(MaterialRequestStatusMail::class, function (MaterialRequestStatusMail $mail) use ($pending) {
            if ($mail->request->isNot($pending) || $mail->request->status !== 'changes_requested') {
                return false;
            }
            $html = $mail->render();

            return ! str_contains($html, 'wa.me') && ! str_contains($html, '250722000111');
        });

        $declined = $this->request($item, '0788999002');
        $declined->update(['api_client_id' => $client->id]);
        $this->actingAs($admin)->postJson("/api/material-requests/{$declined->id}/reject", ['note' => 'No longer needed'])->assertOk();
        $this->assertContactHidden($declined->fresh(), '250722000111');

        $granted = $this->request($item, '0788999003');
        $granted->update(['api_client_id' => $client->id]);
        $this->actingAs($admin)->postJson("/api/material-requests/{$granted->id}/approve", [])->assertOk();
        $this->assertContactShown($granted->fresh(), '250722000111', 'Store desk');
        $granted->update(['status' => 'changes_requested']);
        $this->assertContactHidden($granted->fresh(), '250722000111');
        $granted->update(['status' => 'rejected']);
        $this->assertContactHidden($granted->fresh(), '250722000111');

        app(SettingsService::class)->put(['storekeeper_whatsapp' => '']);
        $storekeeper->update(['phone' => null]);
        $mr->update(['status' => 'approved']);
        $missing = $this->getJson('/api/v1/material-requests/status/'.$mr->uuid)->assertOk();
        $this->assertNull($missing->json('request.decision.storekeeper'));
        $this->assertStringNotContainsString('wa.me', $missing->getContent());
        $this->assertStringNotContainsString('0788999000', $missing->getContent());

        $page = $this->get('/request-status/'.$mr->uuid)->assertOk();
        $page->assertSee("r.status === 'approved'", false);
        $page->assertSee('Message the storekeeper on WhatsApp', false);
        Mail::assertNothingSent();
    }

    private function assertContactHidden(MaterialRequest $mr, string $digits): void
    {
        $decision = app(MaterialRequestDecisionService::class)->decision($mr);
        $this->assertNull($decision['storekeeper']);

        $response = $this->getJson('/api/v1/material-requests/status/'.$mr->uuid)->assertOk();
        $this->assertNull($response->json('request.decision.storekeeper'));
        $this->assertStringNotContainsString($digits, $response->getContent());
        $this->assertStringNotContainsString('wa.me', $response->getContent());

        Http::fake();
        app(MaterialRequestWebhookService::class)->send($mr, 'submitted');
        Http::assertSent(function ($request) use ($digits) {
            $decision = $request->data()['decision'] ?? [];
            $encoded = json_encode($request->data());

            return array_key_exists('storekeeper', $decision)
                && $decision['storekeeper'] === null
                && ! str_contains((string) $encoded, $digits)
                && ! str_contains((string) $encoded, 'wa.me');
        });
    }

    private function assertContactShown(MaterialRequest $mr, string $digits, string $name): TestResponse
    {
        $response = $this->getJson('/api/v1/material-requests/status/'.$mr->uuid)->assertOk();
        $response->assertJsonPath('request.decision.storekeeper.name', $name);
        $this->assertStringStartsWith(
            'https://wa.me/'.$digits.'?text=',
            (string) $response->json('request.decision.storekeeper.whatsapp_url'),
        );
        $this->assertStringContainsString(rawurlencode($mr->reference_code), (string) $response->json('request.decision.storekeeper.whatsapp_url'));

        return $response;
    }

    private function request(EquipmentItem $item, string $phone): MaterialRequest
    {
        $mr = MaterialRequest::query()->create([
            'requester_type' => 'client',
            'full_name' => 'Aline Borrower',
            'phone' => $phone,
            'email' => 'aline@example.com',
            'project_name' => 'Shoot',
            'needed_from' => now()->addDays(2)->toDateString(),
            'return_by' => now()->addDays(4)->toDateString(),
            'status' => 'submitted',
        ]);
        MaterialRequestItem::query()->create([
            'material_request_id' => $mr->id,
            'equipment_item_id' => $item->id,
            'qty_requested' => 1,
            'qty_approved' => 1,
            'status' => 'pending',
        ]);

        return $mr->fresh();
    }
}
