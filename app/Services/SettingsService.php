<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class SettingsService
{
    public const DEFAULTS = [
        'organisation_name' => 'Love In Arts',
        'default_share_days' => 30,
        'require_consent_for_publish' => true,
        'storage_price_per_gb_month' => 0.015,
        'storage_free_gb' => 10,
        'monthly_budget_usd' => 10,
        // Upload defaults so day-to-day uploading needs almost no input.
        'default_review_state' => 'unreviewed',
        'default_tags' => '',                 // comma-separated names applied to new uploads
        'default_captured_at' => true,        // date new uploads as captured today when EXIF is absent
        'default_album_name' => 'Unsorted',   // used when the default album has to be created
        'default_album_uuid' => '',           // explicit default album; empty = auto-managed
        'daily_galleries_enabled' => true,    // auto-file uploads into a per-day gallery
        'gallery_timezone' => 'Africa/Kigali',
    ];

    public function all(): array
    {
        return Cache::remember('settings.all', 300, fn () => array_merge(self::DEFAULTS, Setting::query()->pluck('value', 'key')->all()));
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? self::DEFAULTS[$key] ?? null;
    }

    public function put(array $values): void
    {
        foreach ($values as $k => $v) {
            if (array_key_exists($k, self::DEFAULTS)) {
                Setting::query()->updateOrCreate(['key' => $k], ['value' => $v]);
            }
        }
        Cache::forget('settings.all');
    }
}
