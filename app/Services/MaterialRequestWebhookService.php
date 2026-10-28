<?php

namespace App\Services;

use App\Jobs\SendMaterialRequestWebhook;
use App\Models\MaterialRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MaterialRequestWebhookService
{
    public function dispatchIfConfigured(MaterialRequest $request, ?string $previousStatus = null): void
    {
        $request->loadMissing('apiClient');
        if (! $request->apiClient?->hasWebhook()) {
            return;
        }

        try {
            SendMaterialRequestWebhook::dispatch($request->id, $previousStatus);
        } catch (\Throwable $e) {
            Log::warning('Material request webhook dispatch failed.', [
                'api_client_id' => $request->api_client_id,
                'reference_code' => $request->reference_code,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function dispatchEvent(MaterialRequest $request, string $event): void
    {
        $request->loadMissing('apiClient');
        if (! $request->apiClient?->hasWebhook()) {
            return;
        }

        try {
            SendMaterialRequestWebhook::dispatch($request->id, null, $event);
        } catch (\Throwable $e) {
            Log::warning('Material request webhook dispatch failed.', [
                'api_client_id' => $request->api_client_id,
                'reference_code' => $request->reference_code,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function send(MaterialRequest $request, ?string $previousStatus = null): bool
    {
        $request->loadMissing(['apiClient', 'items.equipmentItem']);
        $client = $request->apiClient;
        if (! $client?->hasWebhook()) {
            return false;
        }

        $secret = (string) $client->prefill_secret;
        if (blank($secret)) {
            Log::warning('Material request webhook skipped: API client has no signing secret.', [
                'api_client_id' => $client->id,
                'reference_code' => $request->reference_code,
            ]);

            return false;
        }

        $payload = [
            'event' => 'material_request.status_changed',
            'reference_code' => $request->reference_code,
            'status' => $request->status,
            'previous_status' => $previousStatus,
            'source' => $request->source,
            'source_detail' => $request->source_detail,
            'requester_type' => $request->requester_type,
            'needed_from' => $request->needed_from?->format('Y-m-d'),
            'return_by' => $request->return_by?->format('Y-m-d'),
            'updated_at' => $request->updated_at?->toIso8601String(),
            'review_note' => $request->review_note,
            'decision' => app(MaterialRequestDecisionService::class)->decision($request),
            'status_url' => app(MaterialRequestDecisionService::class)->statusUrl($request),
            'items' => $request->items->map(fn ($item) => [
                'equipment_item_id' => $item->equipment_item_id,
                'name' => $item->equipmentItem?->name,
                'qty_requested' => $item->qty_requested,
                'qty_approved' => $item->qty_approved,
                'qty_issued' => $item->qty_issued,
                'qty_returned' => $item->qty_returned,
                'status' => $item->status,
            ])->values()->all(),
        ];

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new RuntimeException('Unable to encode material request webhook payload.');
        }

        $response = Http::connectTimeout(5)
            ->timeout(10)
            ->withHeaders([
                'X-LIA-Signature' => 'sha256='.hash_hmac('sha256', $body, $secret),
                'X-LIA-Event' => 'material_request.status_changed',
                'X-LIA-Request' => $request->reference_code,
            ])
            ->withBody($body, 'application/json')
            ->post($client->webhook_url);

        if ($response->failed()) {
            throw new RuntimeException('Material request webhook failed with status '.$response->status().'.');
        }

        return true;
    }

    public function sendEvent(MaterialRequest $request, string $event): bool
    {
        $request->loadMissing(['apiClient']);
        $client = $request->apiClient;
        if (! $client?->hasWebhook()) {
            return false;
        }

        $secret = (string) $client->prefill_secret;
        if (blank($secret)) {
            Log::warning('Material request webhook skipped: API client has no signing secret.', [
                'api_client_id' => $client->id,
                'reference_code' => $request->reference_code,
            ]);

            return false;
        }

        $payload = [
            'event' => $event,
            'reference_code' => $request->reference_code,
            'status' => $request->status,
            'requester_type' => $request->requester_type,
            'final_work_status' => $request->final_work_status,
            'source' => $request->source,
            'source_detail' => $request->source_detail,
            'needed_from' => $request->needed_from?->format('Y-m-d'),
            'return_by' => $request->return_by?->format('Y-m-d'),
            'updated_at' => $request->updated_at?->toIso8601String(),
        ];

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new RuntimeException('Unable to encode material request webhook payload.');
        }

        $response = Http::connectTimeout(5)
            ->timeout(10)
            ->withHeaders([
                'X-LIA-Signature' => 'sha256='.hash_hmac('sha256', $body, $secret),
                'X-LIA-Event' => $event,
                'X-LIA-Request' => $request->reference_code,
            ])
            ->withBody($body, 'application/json')
            ->post($client->webhook_url);

        if ($response->failed()) {
            Log::warning('Material request webhook delivery failed.', [
                'api_client_id' => $client->id,
                'reference_code' => $request->reference_code,
                'status' => $response->status(),
            ]);

            throw new RuntimeException('Material request webhook failed with status '.$response->status().'.');
        }

        return true;
    }
}
