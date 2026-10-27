<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MaterialRequest;
use App\Services\MaterialRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
