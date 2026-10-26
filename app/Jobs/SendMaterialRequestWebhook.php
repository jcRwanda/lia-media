<?php

namespace App\Jobs;

use App\Models\MaterialRequest;
use App\Services\MaterialRequestWebhookService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendMaterialRequestWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /**
     * Create a new job instance.
     */
    public function __construct(public int $materialRequestId, public ?string $previousStatus = null, public ?string $event = null) {}

    /**
     * Execute the job.
     */
    public function handle(MaterialRequestWebhookService $webhooks): void
    {
        $request = MaterialRequest::query()->with(['apiClient', 'items.equipmentItem'])->find($this->materialRequestId);
        if (! $request) {
            return;
        }

        if ($this->event) {
            $webhooks->sendEvent($request, $this->event);

            return;
        }

        $webhooks->send($request, $this->previousStatus);
    }
}
