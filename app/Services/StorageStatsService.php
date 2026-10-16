<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetDerivative;
use App\Models\Setting;
use App\Storage\ObjectStorage;
use Illuminate\Support\Facades\DB;

class StorageStatsService
{
    public function __construct(private SettingsService $settings, private ObjectStorage $storage) {}

    public function estimateUsd(int $bytes): float
    {
        $gb = $bytes / 1024 ** 3;

        return round(max(0, $gb - (float) $this->settings->get('storage_free_gb')) * (float) $this->settings->get('storage_price_per_gb_month'), 2);
    }

    /** Totals from the database (what the archive believes is stored). */
    public function summary(): array
    {
        $orig = (int) Asset::withTrashed()->sum('size_bytes');
        $deriv = (int) AssetDerivative::query()->where('status', 'ready')->sum('size_bytes');
        $byKind = Asset::query()->select('kind', DB::raw('COUNT(*) as n'), DB::raw('SUM(size_bytes) as bytes'))->groupBy('kind')->get()
            ->map(fn ($r) => ['kind' => $r->kind, 'count' => (int) $r->n, 'bytes' => (int) $r->bytes])->values();
        $months = Asset::withTrashed()->where('created_at', '>=', now()->subMonths(11)->startOfMonth())->get(['created_at', 'size_bytes'])
            ->groupBy(fn ($a) => $a->created_at->format('Y-m'))->map(fn ($g, $m) => ['month' => $m, 'count' => $g->count(), 'bytes' => (int) $g->sum('size_bytes')])->sortKeys()->values();
        $total = $orig + $deriv;

        return [
            'originals_bytes' => $orig, 'derivatives_bytes' => $deriv, 'total_bytes' => $total, 'trashed_bytes' => (int) Asset::onlyTrashed()->sum('size_bytes'),
            'asset_count' => Asset::query()->count(), 'by_kind' => $byKind, 'by_month' => $months,
            'estimated_monthly_usd' => $this->estimateUsd($total), 'monthly_budget_usd' => (float) $this->settings->get('monthly_budget_usd'),
            'price_per_gb_month' => (float) $this->settings->get('storage_price_per_gb_month'), 'free_gb' => (float) $this->settings->get('storage_free_gb'),
            'last_snapshot' => Setting::query()->find('storage_snapshot')?->value,
        ];
    }

    /** Count what is really in the bucket (one LIST pass; scheduled daily). */
    public function snapshot(): array
    {
        $u = $this->storage->usage();
        $snap = ['at' => now()->toIso8601String(), 'objects' => $u['objects'], 'bytes' => $u['bytes'], 'by_prefix' => $u['by_prefix'], 'estimated_monthly_usd' => $this->estimateUsd($u['bytes'])];
        Setting::query()->updateOrCreate(['key' => 'storage_snapshot'], ['value' => $snap]);

        return $snap;
    }
}
