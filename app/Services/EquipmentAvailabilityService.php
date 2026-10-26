<?php

namespace App\Services;

use App\Models\EquipmentBlock;
use App\Models\EquipmentItem;
use App\Models\MaterialRequestItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class EquipmentAvailabilityService
{
    public function __construct(private SettingsService $settings) {}

    /**
     * @return array{closed_weekdays: list<int>, max_booking_days: int, lead_time_days: int}
     */
    public function rules(): array
    {
        $settings = $this->settings->all();

        return [
            'closed_weekdays' => array_values(array_map('intval', (array) ($settings['equipment_closed_weekdays'] ?? []))),
            'max_booking_days' => max(1, (int) ($settings['equipment_max_booking_days'] ?? 30)),
            'lead_time_days' => max(0, (int) ($settings['equipment_lead_time_days'] ?? 0)),
        ];
    }

    /**
     * @param  list<int>  $itemIds
     * @return array<string, mixed>
     */
    public function availability(array $itemIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();
        $rules = $this->rules();

        $itemsQuery = EquipmentItem::query()->orderBy('name');
        if ($itemIds !== []) {
            $itemsQuery->whereIn('id', $itemIds);
        } else {
            $itemsQuery->where(function ($query) {
                $query->whereNull('deleted_at');
            });
        }
        $items = $itemsQuery->get();
        $ids = $items->pluck('id')->all();
        $dates = $this->dates($from, $to);
        $reserved = $ids === [] ? [] : $this->reservedByItemAndDate($ids, $from, $to);
        $blocks = $this->blocksFor($ids, $from, $to);

        $globalReasons = [];
        $itemReasons = [];
        foreach ($blocks as $block) {
            $start = $block->starts_on->toImmutable()->startOfDay();
            $end = $block->ends_on->toImmutable()->startOfDay();
            if ($start->lt($from)) {
                $start = $from;
            }
            if ($end->gt($to)) {
                $end = $to;
            }
            foreach ($this->dates($start, $end) as $date) {
                $key = $date->toDateString();
                if ($block->equipment_item_id === null) {
                    $globalReasons[$key][] = $block->reason;
                } else {
                    $itemReasons[$block->equipment_item_id][$key][] = $block->reason;
                }
            }
        }

        foreach ($dates as $date) {
            if (in_array($date->dayOfWeek, $rules['closed_weekdays'], true)) {
                $globalReasons[$date->toDateString()][] = 'Closed weekday';
            }
        }

        $earliestStart = CarbonImmutable::today()->addDays($rules['lead_time_days']);

        $payloadItems = $items->map(function (EquipmentItem $item) use ($dates, $reserved, $globalReasons, $itemReasons, $earliestStart) {
            $daily = [];
            $bookedDates = [];
            $blockedDates = [];
            $unavailableDates = [];

            foreach ($dates as $date) {
                $key = $date->toDateString();
                $reasons = array_values(array_unique(array_merge(
                    $globalReasons[$key] ?? [],
                    $itemReasons[$item->id][$key] ?? [],
                )));
                if ($date->lt($earliestStart)) {
                    $reasons[] = 'Lead time';
                }
                $reasons = array_values(array_unique($reasons));

                $reservedQty = (int) ($reserved[$item->id][$key] ?? 0);
                $availableQty = max(0, $item->total_qty - $reservedQty);
                $blocked = $reasons !== [];
                $unavailable = $blocked || $availableQty <= 0;

                $daily[$key] = [
                    'date' => $key,
                    'reserved_qty' => $reservedQty,
                    'available_qty' => $availableQty,
                    'blocked' => $blocked,
                    'reasons' => $reasons,
                ];

                if ($reservedQty > 0) {
                    $bookedDates[] = $key;
                }
                if ($blocked) {
                    $blockedDates[] = $key;
                }
                if ($unavailable) {
                    $unavailableDates[] = $key;
                }
            }

            return [
                'id' => $item->id,
                'name' => $item->name,
                'total_qty' => $item->total_qty,
                'booked_dates' => $bookedDates,
                'blocked_dates' => $blockedDates,
                'unavailable_dates' => $unavailableDates,
                'daily' => $daily,
            ];
        });

        $globalBlockedDates = collect($globalReasons)
            ->map(fn (array $reasons, string $date) => [
                'date' => $date,
                'reasons' => array_values(array_unique($reasons)),
            ])
            ->sortBy('date')
            ->values()
            ->all();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'rules' => [
                ...$rules,
                'earliest_start_date' => $earliestStart->toDateString(),
            ],
            'global_blocked_dates' => $globalBlockedDates,
            'items' => $payloadItems,
        ];
    }

    /**
     * Validate a set of item lines against rules, blocks and existing reservations.
     *
     * @param  list<array{equipment_item_id: int, qty: int}>  $lines
     *
     * @throws ValidationException
     */
    public function assertCanBook(
        array $lines,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $excludeRequestId = null,
        bool $enforceWindowRules = true,
        bool $lockItems = false,
    ): void {
        $lines = $this->normaliseLines($lines);
        if ($lines === []) {
            return;
        }

        $from = $from->startOfDay();
        $to = $to->startOfDay();
        $rules = $this->rules();
        $itemIds = array_keys($lines);
        sort($itemIds);

        $itemsQuery = EquipmentItem::query()->whereIn('id', $itemIds);
        if ($lockItems) {
            $itemsQuery->lockForUpdate();
        }
        $items = $itemsQuery->get()->keyBy('id');

        $errors = [];
        $days = (int) $from->diffInDays($to) + 1;

        if ($enforceWindowRules) {
            $earliestStart = CarbonImmutable::today()->addDays($rules['lead_time_days']);
            if ($from->lt($earliestStart)) {
                $errors['needed_from'][] = 'The pick-up date must be on or after '.$earliestStart->toDateString().'.';
            }
            if ($days > $rules['max_booking_days']) {
                $errors['return_by'][] = 'A booking can be at most '.$rules['max_booking_days'].' day(s) long.';
            }
            foreach ($this->dates($from, $to) as $date) {
                if (in_array($date->dayOfWeek, $rules['closed_weekdays'], true)) {
                    $errors['needed_from'][] = 'The selected dates include a closed day ('.$date->format('l, j M Y').').';
                    break;
                }
            }
        }

        foreach ($this->blocksFor($itemIds, $from, $to) as $block) {
            $lineIndex = $block->equipment_item_id === null ? null : array_search($block->equipment_item_id, $itemIds, true);
            if ($block->equipment_item_id !== null && $lineIndex === false) {
                continue;
            }

            $message = 'The selected dates overlap a blocked period'.($block->reason ? ' ('.$block->reason.')' : '').'.';
            if ($lineIndex === null) {
                $errors['needed_from'][] = $message;
            } else {
                $errors['items.'.$lineIndex][] = $message;
            }
        }

        $reserved = $this->reservedByItemAndDate($itemIds, $from, $to, $excludeRequestId);
        $dates = $this->dates($from, $to);
        $linePositions = array_flip($itemIds);

        foreach ($lines as $itemId => $qty) {
            $item = $items->get($itemId);
            if (! $item) {
                continue;
            }

            foreach ($dates as $date) {
                $key = $date->toDateString();
                $available = max(0, $item->total_qty - (int) ($reserved[$item->id][$key] ?? 0));
                if ($qty <= $available) {
                    continue;
                }

                $errors['items.'.$linePositions[$itemId].'.qty'][] = 'Only '.$available.' of '.$item->name.' available on '.$key.' (requested '.$qty.').';
                break;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  list<int>  $itemIds
     * @return array<int, array<string, int>>
     */
    private function reservedByItemAndDate(array $itemIds, CarbonImmutable $from, CarbonImmutable $to, ?int $excludeRequestId = null): array
    {
        if ($itemIds === []) {
            return [];
        }

        $rows = MaterialRequestItem::query()
            ->join('material_requests', 'material_requests.id', '=', 'material_request_items.material_request_id')
            ->whereIn('material_request_items.equipment_item_id', $itemIds)
            ->whereIn('material_requests.status', ['approved', 'issued'])
            ->whereNull('material_requests.deleted_at')
            ->whereDate('material_requests.needed_from', '<=', $to->toDateString())
            ->whereDate('material_requests.return_by', '>=', $from->toDateString())
            ->when($excludeRequestId, fn ($query) => $query->where('material_requests.id', '!=', $excludeRequestId))
            ->get([
                'material_request_items.equipment_item_id',
                'material_request_items.qty_requested',
                'material_request_items.qty_approved',
                'material_request_items.qty_issued',
                'material_requests.needed_from',
                'material_requests.return_by',
            ]);

        $reserved = [];
        foreach ($rows as $row) {
            $qty = $row->qty_issued !== null
                ? (int) $row->qty_issued
                : (int) ($row->qty_approved ?? $row->qty_requested);
            if ($qty <= 0) {
                continue;
            }

            $start = CarbonImmutable::parse((string) $row->needed_from)->startOfDay();
            $end = CarbonImmutable::parse((string) $row->return_by)->startOfDay();
            if ($start->lt($from)) {
                $start = $from;
            }
            if ($end->gt($to)) {
                $end = $to;
            }
            foreach ($this->dates($start, $end) as $date) {
                $key = $date->toDateString();
                $reserved[(int) $row->equipment_item_id][$key] = ($reserved[(int) $row->equipment_item_id][$key] ?? 0) + $qty;
            }
        }

        return $reserved;
    }

    /**
     * @param  list<int>  $itemIds
     * @return Collection<int, EquipmentBlock>
     */
    private function blocksFor(array $itemIds, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return EquipmentBlock::query()
            ->whereDate('starts_on', '<=', $to->toDateString())
            ->whereDate('ends_on', '>=', $from->toDateString())
            ->where(function ($query) use ($itemIds) {
                $query->whereNull('equipment_item_id');
                if ($itemIds !== []) {
                    $query->orWhereIn('equipment_item_id', $itemIds);
                }
            })
            ->get();
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function dates(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();
        if ($from->gt($to)) {
            return [];
        }

        $dates = [];
        for ($cursor = $from; $cursor->lte($to); $cursor = $cursor->addDay()) {
            $dates[] = $cursor;
        }

        return $dates;
    }

    /**
     * @param  list<array{equipment_item_id: int, qty: int}>  $lines
     * @return array<int, int>
     */
    private function normaliseLines(array $lines): array
    {
        $normalised = [];
        foreach ($lines as $line) {
            $itemId = (int) ($line['equipment_item_id'] ?? 0);
            $qty = (int) ($line['qty'] ?? 0);
            if ($itemId <= 0 || $qty <= 0) {
                continue;
            }
            $normalised[$itemId] = ($normalised[$itemId] ?? 0) + $qty;
        }

        return $normalised;
    }
}
