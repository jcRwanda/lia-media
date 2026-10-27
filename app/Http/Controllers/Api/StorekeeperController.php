<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EquipmentItem;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Services\MaterialRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class StorekeeperController extends Controller
{
    public function __construct(private MaterialRequestService $files) {}

    /**
     * Bookings grouped into storekeeper hand-out / check-in tabs.
     */
    public function bookings(Request $request): JsonResponse
    {
        if (! $request->user()?->canDo('material-requests.view')) {
            abort(403);
        }

        $today = now()->toDateString();
        $with = ['items.equipmentItem.images', 'items.proofs', 'items.issuedBy:id,name', 'items.receivedBy:id,name', 'approvedBy:id,name', 'apiClient:id,name'];

        $toHandOut = MaterialRequest::query()
            ->with($with)
            ->where('status', 'approved')
            ->where(function ($q) use ($today) {
                $q->whereNull('needed_from')->orWhereDate('needed_from', '<=', $today);
            })
            ->orderBy('needed_from')
            ->orderBy('id')
            ->get();

        $outNow = MaterialRequest::query()
            ->with($with)
            ->whereIn('status', ['issued', 'returned'])
            ->orderBy('return_by')
            ->orderBy('id')
            ->get();

        $dueOverdue = MaterialRequest::query()
            ->with($with)
            ->whereIn('status', ['issued', 'returned'])
            ->whereNotNull('return_by')
            ->whereDate('return_by', '<=', $today)
            ->orderBy('return_by')
            ->orderBy('id')
            ->get();

        $history = MaterialRequest::query()
            ->with($with)
            ->whereIn('status', ['closed', 'rejected'])
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get();

        $tab = (string) $request->query('tab', 'to_hand_out');
        $map = [
            'to_hand_out' => $toHandOut,
            'out_now' => $outNow,
            'due_overdue' => $dueOverdue,
            'history' => $history,
        ];
        $active = $map[$tab] ?? $toHandOut;

        return response()->json([
            'tab' => array_key_exists($tab, $map) ? $tab : 'to_hand_out',
            'counts' => [
                'to_hand_out' => $toHandOut->count(),
                'out_now' => $outNow->count(),
                'due_overdue' => $dueOverdue->count(),
                'history' => $history->count(),
            ],
            'bookings' => collect($active)->map(fn (MaterialRequest $mr) => $this->present($mr))->values(),
        ]);
    }

    /**
     * Storekeeper dashboard data.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! ($user?->canDo('material-requests.issue') || $user?->canDo('equipment.manage'))) {
            abort(403);
        }

        $today = now()->toDateString();
        $startOfWeek = now()->startOfWeek()->toDateString();
        $endOfWeek = now()->endOfWeek()->toDateString();

        $with = ['items.equipmentItem.images', 'items.proofs', 'items.issuedBy:id,name', 'items.receivedBy:id,name', 'approvedBy:id,name', 'apiClient:id,name'];

        // To hand out today (approved, needed_from null or <= today)
        $toHandOutToday = MaterialRequest::query()
            ->with($with)
            ->where('status', 'approved')
            ->where(function ($q) use ($today) {
                $q->whereNull('needed_from')->orWhereDate('needed_from', '<=', $today);
            })
            ->orderBy('needed_from')
            ->orderBy('id')
            ->get();

        // Currently out
        $currentlyOut = MaterialRequest::query()
            ->with($with)
            ->where('status', 'issued')
            ->orderBy('return_by')
            ->orderBy('id')
            ->get();

        // Due today
        $dueToday = MaterialRequest::query()
            ->with($with)
            ->where('status', 'issued')
            ->whereNotNull('return_by')
            ->whereDate('return_by', $today)
            ->orderBy('id')
            ->get();

        // Overdue
        $overdue = MaterialRequest::query()
            ->with($with)
            ->where('status', 'issued')
            ->whereNotNull('return_by')
            ->whereDate('return_by', '<', $today)
            ->orderBy('return_by')
            ->orderBy('id')
            ->get();

        // Returned this week (items returned between start and end of week)
        $returnedThisWeek = MaterialRequestItem::query()
            ->whereHas('request', function ($q) {
                $q->whereIn('status', ['issued', 'returned', 'closed']);
            })
            ->whereNotNull('returned_at')
            ->whereBetween('returned_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->count();

        // Equipment inventory stats
        $equipmentItems = EquipmentItem::query()->get();
        $itemsInInventory = $equipmentItems->sum(function ($item) {
            return (int) ($item->total_qty ?? 0);
        });
        if ($itemsInInventory === 0) {
            $itemsInInventory = $equipmentItems->count();
        }

        $lowUnavailable = $equipmentItems->filter(function ($item) {
            $qty = (int) ($item->total_qty ?? 0);
            if ($qty <= 0) {
                return true;
            }
            // consider low if small quantity
            if ($qty <= 2) {
                return true;
            }

            return false;
        })->count();

        $damagedNeedsRepair = $equipmentItems->filter(function ($item) {
            $condition = strtolower((string) ($item->condition ?? ''));
            if (str_contains($condition, 'damag') || str_contains($condition, 'repair') || str_contains($condition, 'broken') || str_contains($condition, 'service')) {
                return true;
            }

            return false;
        })->count();

        // Next 7 days upcoming bookings
        $next7Days = MaterialRequest::query()
            ->with($with)
            ->whereIn('status', ['approved', 'issued'])
            ->whereNotNull('needed_from')
            ->whereBetween('needed_from', [$today, now()->addDays(7)->toDateString()])
            ->orderBy('needed_from')
            ->orderBy('id')
            ->get();

        // Recent activity - last hand-outs/returns
        $recentItems = MaterialRequestItem::query()
            ->with(['request', 'equipmentItem.images', 'proofs', 'issuedBy', 'receivedBy'])
            ->where(function ($q) {
                $q->whereNotNull('issued_at')->orWhereNotNull('returned_at');
            })
            ->orderByRaw('COALESCE(returned_at, issued_at) DESC')
            ->limit(20)
            ->get();

        $todayList = collect()
            ->merge($toHandOutToday->map(function ($mr) {
                $presented = $this->present($mr);
                $presented['type'] = 'pickup';

                return $presented;
            }))
            ->merge($currentlyOut->take(50)->map(function ($mr) {
                $presented = $this->present($mr);
                $presented['type'] = 'return';

                return $presented;
            }))
            ->unique('id')
            ->values();

        $overdueList = $overdue->map(fn ($mr) => $this->present($mr))->values();

        $upcoming = $next7Days->map(fn ($mr) => $this->present($mr))->values();

        $recentActivity = $recentItems->map(function ($item) {
            $mr = $item->request;
            $proofs = $item->proofs->map(function ($p) {
                try {
                    return $this->files->presign($p->path, $p->name);
                } catch (\Throwable $e) {
                    return null;
                }
            })->filter()->values()->all();

            return [
                'id' => $item->id,
                'material_request_id' => $mr?->id,
                'reference_code' => $mr?->reference_code,
                'requester_name' => $mr?->full_name,
                'equipment_name' => $item->equipmentItem?->name,
                'type' => $item->returned_at && (! $item->issued_at || $item->returned_at >= $item->issued_at) ? 'return' : 'hand_out',
                'issued_at' => $item->issued_at,
                'returned_at' => $item->returned_at,
                'qty_issued' => $item->qty_issued,
                'qty_returned' => $item->qty_returned,
                'proof_thumbnails' => array_slice($proofs, 0, 4),
                'issued_by' => $item->issuedBy?->name,
                'received_by' => $item->receivedBy?->name,
            ];
        })->values();

        return response()->json([
            'stats' => [
                'to_hand_out_today' => $toHandOutToday->count(),
                'currently_out' => $currentlyOut->count(),
                'due_today' => $dueToday->count(),
                'overdue' => $overdue->count(),
                'returned_this_week' => $returnedThisWeek,
                'items_in_inventory' => $itemsInInventory,
                'items_low_unavailable' => $lowUnavailable,
                'damaged_needs_repair' => $damagedNeedsRepair,
            ],
            'today' => $todayList,
            'overdue' => $overdueList,
            'upcoming' => $upcoming,
            'recent_activity' => $recentActivity,
        ]);
    }

    private function present(MaterialRequest $mr): array
    {
        $payload = $mr->toArray();
        $payload['source'] = $mr->source ?? $mr->apiClient?->name ?? 'Direct/Hosted form';
        $payload['source_label'] = $payload['source'];
        $payload['is_overdue'] = $mr->isOverdue();
        $payload['pickup_location'] = $mr->project_location
            ?: $mr->shoot_location
            ?: collect([$mr->project_cell, $mr->project_sector, $mr->project_district, $mr->project_province])->filter()->join(', ')
            ?: $mr->residential_address;
        $payload['items'] = $mr->items->map(function ($item) {
            $data = $item->toArray();
            $data['issued_by'] = $item->issuedBy?->name;
            $data['received_by'] = $item->receivedBy?->name;
            $data['issue_proof_urls'] = $item->proofs
                ->where('type', 'issue')
                ->map(fn ($p) => $this->files->presign($p->path, $p->name))
                ->filter()
                ->values()
                ->all();
            $data['return_proof_urls'] = $item->proofs
                ->where('type', 'return')
                ->map(fn ($p) => $this->files->presign($p->path, $p->name))
                ->filter()
                ->values()
                ->all();
            $data['equipment_item'] = $item->equipmentItem ? [
                ...$item->equipmentItem->toArray(),
                'images' => $item->equipmentItem->images->map(fn ($img) => [
                    'id' => $img->id,
                    'name' => $img->name,
                    'url' => $this->files->presign($img->path, $img->name),
                ])->values()->all(),
            ] : null;

            return $data;
        })->values()->all();

        return $payload;
    }
}
