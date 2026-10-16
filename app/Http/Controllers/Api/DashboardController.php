<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\ShareLink;
use App\Models\UploadSession;
use App\Services\AssetPresenter;
use App\Services\StorageStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __construct(private StorageStatsService $stats, private AssetPresenter $present) {}

    public function index(): JsonResponse
    {
        $states = Asset::query()->select('review_state', DB::raw('COUNT(*) as n'))->groupBy('review_state')->pluck('n', 'review_state');
        $summary = $this->stats->summary();

        return response()->json([
            'review_counts' => collect(Asset::REVIEW_STATES)->mapWithKeys(fn ($s) => [$s => (int) ($states[$s] ?? 0)]),
            'consent_issues' => Asset::query()->whereIn('consent_status', ['missing', 'withdrawn'])->whereIn('review_state', ['approved', 'published'])->count(),
            'uploads_last_7_days' => Asset::query()->where('created_at', '>=', now()->subDays(7))->count(),
            'open_uploads' => UploadSession::query()->whereIn('status', ['pending', 'uploading'])->count(),
            'active_shares' => ShareLink::query()->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count(),
            'storage' => ['total_bytes' => $summary['total_bytes'], 'asset_count' => $summary['asset_count'], 'estimated_monthly_usd' => $summary['estimated_monthly_usd'], 'monthly_budget_usd' => $summary['monthly_budget_usd']],
            'recent' => Asset::query()->with('derivatives')->latest('id')->limit(8)->get()->map(fn ($a) => $this->present->card($a)),
        ]);
    }

    public function storage(): JsonResponse
    {
        return response()->json($this->stats->summary());
    }

    public function snapshot(): JsonResponse
    {
        return response()->json($this->stats->snapshot());
    }
}
