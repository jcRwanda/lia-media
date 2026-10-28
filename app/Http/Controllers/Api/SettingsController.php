<?php

namespace App\Http\Controllers\Api;

use App\Backup\BackupDriver;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function __construct(private SettingsService $settings) {}

    private function payload(BackupDriver $backup): array
    {
        return [
            'settings' => array_intersect_key($this->settings->all(), SettingsService::DEFAULTS),
            'system' => [
                'storage_driver' => config('media.disk'), 'bucket' => config('media.r2.bucket'),
                'storage_configured' => filled(config('media.r2.key')) && filled(config('media.r2.endpoint')) && ! str_contains((string) config('media.r2.endpoint'), 'your-cloudflare-account-id'),
                'backup_driver' => $backup->name(), 'backup_enabled' => $backup->enabled(),
                'url_ttl' => config('media.ttl'), 'multipart_threshold' => config('media.multipart_threshold'), 'max_file_size' => config('media.max_file_size'),
                'queue' => config('queue.default'),
            ],
        ];
    }

    public function show(BackupDriver $backup): JsonResponse
    {
        return response()->json($this->payload($backup));
    }

    public function update(Request $request, BackupDriver $backup, AuditLogger $audit): JsonResponse
    {
        $d = $request->validate([
            'organisation_name' => ['sometimes', 'string', 'max:120'],
            'default_share_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'require_consent_for_publish' => ['sometimes', 'boolean'],
            'storage_price_per_gb_month' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'storage_free_gb' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'monthly_budget_usd' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'default_review_state' => ['sometimes', Rule::in(Asset::REVIEW_STATES)],
            'default_tags' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'default_captured_at' => ['sometimes', 'boolean'],
            'default_album_name' => ['sometimes', 'string', 'max:120'],
            'default_album_uuid' => ['sometimes', 'nullable', 'string', Rule::exists('albums', 'uuid')->whereNull('deleted_at')],
            'daily_galleries_enabled' => ['sometimes', 'boolean'],
            'storekeeper_whatsapp' => ['sometimes', 'nullable', 'string', 'max:40'],
            'storekeeper_contact_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'gallery_timezone' => ['sometimes', 'string', Rule::in(timezone_identifiers_list())],
        ]);
        $this->settings->put($d);
        $audit->log('settings.updated', null, $d);

        return response()->json($this->payload($backup));
    }
}
