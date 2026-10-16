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
