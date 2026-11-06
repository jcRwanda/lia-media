<?php

namespace App\Services;

use App\Models\EquipmentItem;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaterialRequestPickupItemService
{
    public function __construct(
        private EquipmentAvailabilityService $availability,
        private MaterialRequestDecisionService $decisions,
        private MaterialRequestAuditService $audit,
        private MaterialRequestWebhookService $webhooks,
        private MaterialRequestService $files,
    ) {}

    public function isUnavailable(EquipmentItem $item): bool
    {
        if ((int) $item->total_qty <= 0) {
            return true;
        }

        $condition = strtolower((string) ($item->condition ?? ''));

        return str_contains($condition, 'damag')
            || str_contains($condition, 'repair')
            || str_contains($condition, 'broken')
            || str_contains($condition, 'service')
            || str_contains($condition, 'unavailable');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function catalog(MaterialRequest $request, ?string $search = null, int $limit = 40): array
    {
        $this->assertApproved($request);

        $from = $this->from($request);
        $to = $this->to($request);
        $already = $request->items()->pluck('equipment_item_id')->all();

        $query = EquipmentItem::query()
            ->with(['images', 'accessories'])
            ->orderBy('name')
            ->limit(max(1, min($limit, 100)));

        if ($search !== null && trim($search) !== '') {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($search)).'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('category', 'like', $term)
                    ->orWhere('serial_number', 'like', $term)
                    ->orWhere('asset_tag', 'like', $term);
            });
        }

        $items = $query->get();
        $availability = $this->availability->availability($items->pluck('id')->all(), $from, $to);
        $byId = collect($availability['items'])->keyBy('id');

        return $items->map(function (EquipmentItem $item) use ($already, $byId, $from, $to) {
            $daily = $byId->get($item->id)['daily'] ?? [];
            $minAvailable = $daily === []
                ? (int) $item->total_qty
                : min(array_map(fn ($d) => (int) $d['available_qty'], $daily));
            // Availability excludes this request; add back qty already reserved by it for other items of same id (none if not on request).
            $unavailable = $this->isUnavailable($item) || in_array($item->id, $already, true);
            $photo = $item->photo_path ?: $item->images->first()?->path;

            return [
                'id' => $item->id,
                'name' => $item->name,
                'category' => $item->category,
                'serial_number' => $item->serial_number,
                'asset_tag' => $item->asset_tag,
                'condition' => $item->condition,
                'total_qty' => $item->total_qty,
                'available_qty' => max(0, $minAvailable),
                'already_on_request' => in_array($item->id, $already, true),
                'unavailable' => $unavailable || $minAvailable <= 0,
                'unavailable_reason' => $this->isUnavailable($item)
                    ? 'Item is marked unavailable or needs repair.'
                    : (in_array($item->id, $already, true)
                        ? 'Already on this request.'
                        : ($minAvailable <= 0 ? 'Fully booked for these dates.' : null)),
                'photo_url' => $photo ? $this->files->presign($photo, $item->name) : null,
                'images' => $item->images->map(fn ($img) => [
                    'id' => $img->id,
                    'url' => $this->files->presign($img->path, $img->name),
                ])->values()->all(),
                'accessories' => $item->accessories->map(fn ($a) => [
                    'id' => $a->id,
                    'name' => $a->name,
                ])->values()->all(),
                'needed_from' => $from->toDateString(),
                'return_by' => $to->toDateString(),
            ];
        })->values()->all();
    }

    public function add(MaterialRequest $request, User $actor, int $equipmentItemId, int $qty, ?string $note = null): MaterialRequestItem
    {
        $this->assertApproved($request);
        if ($qty < 1) {
            throw ValidationException::withMessages(['qty' => ['Quantity must be at least 1.']]);
        }

        return DB::transaction(function () use ($request, $actor, $equipmentItemId, $qty, $note) {
            $item = EquipmentItem::query()->lockForUpdate()->find($equipmentItemId);
            if (! $item) {
                throw ValidationException::withMessages(['equipment_item_id' => ['Equipment item not found.']]);
            }
            if ($this->isUnavailable($item)) {
                throw ValidationException::withMessages(['equipment_item_id' => ['This item is unavailable or needs repair.']]);
            }

            $existing = $request->items()->where('equipment_item_id', $item->id)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->qty_issued || $existing->status === 'issued') {
                    throw ValidationException::withMessages(['equipment_item_id' => ['This item is already on the request and has been issued.']]);
                }
                throw ValidationException::withMessages(['equipment_item_id' => ['This item is already on the request.']]);
            }

            $from = $this->from($request);
            $to = $this->to($request);
            $lines = $request->items()->get()->map(fn (MaterialRequestItem $line) => [
                'equipment_item_id' => (int) $line->equipment_item_id,
                'qty' => (int) ($line->qty_approved ?? $line->qty_requested),
            ])->all();
            $lines[] = ['equipment_item_id' => $item->id, 'qty' => $qty];

            $this->availability->assertCanBook(
                $lines,
                $from,
                $to,
                $request->id,
                enforceWindowRules: false,
                lockItems: true,
            );

            $line = MaterialRequestItem::query()->create([
                'material_request_id' => $request->id,
                'equipment_item_id' => $item->id,
                'qty_requested' => $qty,
                'qty_approved' => $qty,
                'status' => 'approved',
                'note' => $note,
                'added_at_pickup' => true,
                'added_by' => $actor->id,
                'added_at' => now(),
            ]);

            $historyNote = 'Added at pickup by '.$actor->name.': '.$qty.' × '.$item->name;
            $this->decisions->record($request, $request->status, 'pickup_item_added', $historyNote, $actor);
            $this->audit->log($request, 'pickup_item_added', [
                'material_request_item_id' => $line->id,
                'equipment_item_id' => $item->id,
                'equipment_name' => $item->name,
                'qty' => $qty,
            ], $actor);

            $this->webhooks->dispatchEvent($request->fresh(['apiClient']), 'material_request.pickup_item_added');

            return $line->load(['equipmentItem.images', 'equipmentItem.accessories', 'addedBy:id,name']);
        });
    }

    public function remove(MaterialRequest $request, User $actor, MaterialRequestItem $line): void
    {
        $this->assertApproved($request);

        if ($line->material_request_id !== $request->id) {
            throw ValidationException::withMessages(['item' => ['Item does not belong to this request.']]);
        }
        if (! $line->added_at_pickup) {
            throw ValidationException::withMessages(['item' => ['Only items added at pickup can be removed here.']]);
        }
        if ($line->qty_issued || $line->status === 'issued' || $line->issued_at) {
            throw ValidationException::withMessages(['item' => ['This item has already been issued and cannot be removed.']]);
        }

        DB::transaction(function () use ($request, $actor, $line) {
            $name = $line->equipmentItem?->name ?? 'item';
            $qty = (int) ($line->qty_approved ?? $line->qty_requested);
            $lineId = $line->id;
            $equipmentId = $line->equipment_item_id;
            $line->delete();

            $historyNote = 'Removed pickup add by '.$actor->name.': '.$qty.' × '.$name;
            $this->decisions->record($request, $request->status, 'pickup_item_removed', $historyNote, $actor);
            $this->audit->log($request, 'pickup_item_removed', [
                'material_request_item_id' => $lineId,
                'equipment_item_id' => $equipmentId,
                'equipment_name' => $name,
                'qty' => $qty,
            ], $actor);
            $this->webhooks->dispatchEvent($request->fresh(['apiClient']), 'material_request.pickup_item_removed');
        });
    }

    public function resolveByQr(string $token): EquipmentItem
    {
        $item = EquipmentItem::query()->where('qr_token', $token)->first();
        if (! $item) {
            throw ValidationException::withMessages(['qr_token' => ['No equipment found for that QR code.']]);
        }

        return $item;
    }

    private function assertApproved(MaterialRequest $request): void
    {
        if ($request->status !== 'approved') {
            throw ValidationException::withMessages(['status' => ['Items can only be added at pickup while the request is approved.']]);
        }
        if (! $request->needed_from || ! $request->return_by) {
            throw ValidationException::withMessages(['needed_from' => ['This request is missing pick-up or return dates.']]);
        }
    }

    private function from(MaterialRequest $request): CarbonImmutable
    {
        return CarbonImmutable::parse($request->needed_from->toDateString())->startOfDay();
    }

    private function to(MaterialRequest $request): CarbonImmutable
    {
        return CarbonImmutable::parse($request->return_by->toDateString())->startOfDay();
    }
}
