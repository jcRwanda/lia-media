<?php

namespace App\Services;

use App\Models\EquipmentBlock;
use App\Models\EquipmentItem;
use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Support\BookingSchedule;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class EquipmentAvailabilityService
{
    public const TIMEZONE = 'Africa/Kigali';

    public const MAX_ACTIVE_OVERLAPS = 3;

    /** @var list<string> */
    public const ACTIVE_OVERLAP_STATUSES = ['submitted', 'changes_requested', 'approved', 'issued'];

    /** @var list<string> */
    public const PENDING_OVERLAP_STATUSES = ['submitted', 'changes_requested'];

    public function __construct(private SettingsService $settings, private BookingSchedule $schedule) {}

    public function bookingDay(mixed $value): CarbonImmutable
    {
        $day = $value instanceof DateTimeInterface
            ? $value->format('Y-m-d')
            : CarbonImmutable::parse((string) $value, self::TIMEZONE)->toDateString();

        return CarbonImmutable::parse($day, self::TIMEZONE)->startOfDay();
    }

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
        $from = $this->bookingDay($from);
        $to = $this->bookingDay($to);
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
        $overlapCounts = $ids === [] ? [] : $this->overlapCountsByItemAndDate($ids, $from, $to);
        $blocks = $this->blocksFor($ids, $from, $to);

        $globalReasons = [];
        $itemReasons = [];
        foreach ($blocks as $block) {
            $start = $this->bookingDay($block->starts_on);
            $end = $this->bookingDay($block->ends_on);
            if ($start->toDateString() < $from->toDateString()) {
                $start = $from;
            }
            if ($end->toDateString() > $to->toDateString()) {
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

        $earliestStart = CarbonImmutable::now(self::TIMEZONE)->startOfDay()->addDays($rules['lead_time_days']);

        $payloadItems = $items->map(function (EquipmentItem $item) use ($dates, $reserved, $overlapCounts, $globalReasons, $itemReasons, $earliestStart) {
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

                $counts = $overlapCounts[$item->id][$key] ?? ['active' => 0, 'pending' => 0];
                $daily[$key] = [
                    'date' => $key,
                    'reserved_qty' => $reservedQty,
                    'available_qty' => $availableQty,
                    'pending_request_count' => $counts['pending'],
                    'active_request_count' => $counts['active'],
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
                'timezone' => self::TIMEZONE,
                'max_overlapping_requests' => self::MAX_ACTIVE_OVERLAPS,
                'overlap_statuses' => self::ACTIVE_OVERLAP_STATUSES,
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
        ?CarbonImmutable $windowStart = null,
        ?CarbonImmutable $windowEnd = null,
    ): void {
        $lines = $this->normaliseLines($lines);
        if ($lines === []) {
            return;
        }

        $from = $this->bookingDay($from);
        $to = $this->bookingDay($to);
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
            $earliestStart = CarbonImmutable::now(self::TIMEZONE)->startOfDay()->addDays($rules['lead_time_days']);
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

        $reserved = $this->reservedByItemAndDate($itemIds, $from, $to, $excludeRequestId, $windowStart, $windowEnd);
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
     * Queue limit for the same equipment on overlapping Kigali calendar dates.
     * Requests 1–3 may be stored. The 4th is rejected. Physical stock is unchanged.
     *
     * @param  list<array{equipment_item_id: int, qty: int}>  $lines
     * @return array{timezone: string, max_active_requests: int, date_semantics: string, level: string, summary: array{en: string, kn: string}, warnings: list<array<string, mixed>>}
     */
    public function assertBookingOverlap(
        array $lines,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $excludeRequestId = null,
        bool $lock = false,
    ): array {
        $assessment = $this->bookingOverlap($lines, $from, $to, $excludeRequestId, $lock);
        $errors = [];
        foreach ($assessment['warnings'] as $warning) {
            if ($warning['level'] !== 'blocked') {
                continue;
            }
            $index = $warning['line_index'];
            $text = $warning['message']['en'].' / '.$warning['message']['kn'];
            $errors['items.'.$index][] = $text;
            $errors['needed_from'][] = $text;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $assessment;
    }

    /**
     * @param  list<array{equipment_item_id: int, qty: int}>  $lines
     * @return array{timezone: string, max_active_requests: int, date_semantics: string, level: string, summary: array{en: string, kn: string}, warnings: list<array<string, mixed>>}
     */
    public function bookingOverlap(
        array $lines,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?int $excludeRequestId = null,
        bool $lock = false,
    ): array {
        $windowStart = $from->utc();
        $windowEnd = $to->utc();
        $indexByItem = [];
        $itemIds = [];
        foreach (array_values($lines) as $index => $line) {
            $itemId = (int) ($line['equipment_item_id'] ?? 0);
            if ($itemId <= 0 || isset($indexByItem[$itemId])) {
                continue;
            }
            $indexByItem[$itemId] = $index;
            $itemIds[] = $itemId;
        }
        sort($itemIds);

        if ($lock && $itemIds !== []) {
            EquipmentItem::query()->whereIn('id', $itemIds)->orderBy('id')->lockForUpdate()->get(['id']);
        }

        $names = EquipmentItem::query()->whereIn('id', $itemIds)->pluck('name', 'id');
        $existing = $this->overlappingRequests($itemIds, $windowStart, $windowEnd, $excludeRequestId, $lock);
        $warnings = [];

        foreach ($itemIds as $itemId) {
            $before = count($existing[$itemId] ?? []);
            $position = $before + 1;
            $name = (string) ($names[$itemId] ?? 'This item');
            $message = $this->overlapMessage($name, $before, $position);
            $level = $position > self::MAX_ACTIVE_OVERLAPS
                ? 'blocked'
                : ($before === 0 ? 'pending' : 'not_guaranteed');
            $warnings[] = [
                'equipment_item_id' => $itemId,
                'equipment_name' => $name,
                'active_requests_before' => $before,
                'position' => $position,
                'level' => $level,
                'guaranteed' => false,
                'line_index' => $indexByItem[$itemId],
                'message' => $message,
            ];
        }

        $level = collect($warnings)->contains(fn (array $warning) => $warning['level'] === 'blocked')
            ? 'blocked'
            : (collect($warnings)->contains(fn (array $warning) => $warning['level'] === 'not_guaranteed')
                ? 'not_guaranteed'
                : 'pending');

        return [
            'timezone' => self::TIMEZONE,
            'max_active_requests' => self::MAX_ACTIVE_OVERLAPS,
            'date_semantics' => 'inclusive_kigali_calendar_dates',
            'level' => $level,
            'summary' => $this->overlapSummary($level),
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  list<int>  $itemIds
     * @return array<int, array<string, array{active: int, pending: int}>>
     */
    private function overlapCountsByItemAndDate(array $itemIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = $this->bookingDay($from);
        $to = $this->bookingDay($to);
        $counts = [];
        foreach ($this->overlapRows($itemIds, $from, $to, null) as $row) {
            $start = $this->dateString($row->needed_from);
            $end = $this->dateString($row->return_by);
            if ($start === '' || $end === '' || ! $this->rangesOverlap($start, $end, $from->toDateString(), $to->toDateString())) {
                continue;
            }
            $windowStart = max($start, $from->toDateString());
            $windowEnd = min($end, $to->toDateString());
            $itemId = (int) $row->equipment_item_id;
            $requestId = (int) $row->request_id;
            $pending = in_array((string) $row->request_status, self::PENDING_OVERLAP_STATUSES, true);
            foreach ($this->dates($this->bookingDay($windowStart), $this->bookingDay($windowEnd)) as $date) {
                $key = $date->toDateString();
                $counts[$itemId][$key]['requests'][$requestId] = $pending;
            }
        }

        $result = [];
        foreach ($counts as $itemId => $days) {
            foreach ($days as $key => $bucket) {
                $requests = $bucket['requests'];
                $result[$itemId][$key] = [
                    'active' => count($requests),
                    'pending' => count(array_filter($requests)),
                ];
            }
        }

        return $result;
    }

    /**
     * @param  list<int>  $itemIds
     * @return array<int, array<int, true>>
     */
    private function overlappingRequests(array $itemIds, CarbonImmutable $start, CarbonImmutable $end, ?int $excludeRequestId, bool $lock): array
    {
        $rows = $this->overlapRows($itemIds, $start, $end, $excludeRequestId);
        $requestIds = $rows->pluck('request_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        if ($lock && $requestIds !== []) {
            MaterialRequest::query()->whereIn('id', $requestIds)->orderBy('id')->lockForUpdate()->get(['id']);
            $rows = $this->overlapRows($itemIds, $start, $end, $excludeRequestId);
        }

        $existing = [];
        foreach ($rows as $row) {
            [$rowStart, $rowEnd] = $this->rowInterval($row);
            if (! $this->schedule->overlaps($start, $end, $rowStart, $rowEnd)) {
                continue;
            }
            $existing[(int) $row->equipment_item_id][(int) $row->request_id] = true;
        }

        return $existing;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function rowInterval(object $row): array
    {
        return $this->schedule->range(
            [
                'date' => $this->dateString($row->needed_from),
                'at' => $row->needed_from_at ? CarbonImmutable::parse($row->needed_from_at)->utc() : null,
                'time_specified' => $row->needed_from_at !== null,
            ],
            [
                'date' => $this->dateString($row->return_by),
                'at' => $row->return_by_at ? CarbonImmutable::parse($row->return_by_at)->utc() : null,
                'time_specified' => $row->return_by_at !== null,
            ],
        );
    }

    /**
     * @param  list<int>  $itemIds
     */
    private function overlapRows(array $itemIds, CarbonImmutable $from, CarbonImmutable $to, ?int $excludeRequestId): Collection
    {
        if ($itemIds === []) {
            return collect();
        }

        return MaterialRequestItem::query()
            ->join('material_requests', 'material_requests.id', '=', 'material_request_items.material_request_id')
            ->whereIn('material_request_items.equipment_item_id', $itemIds)
            ->whereIn('material_requests.status', self::ACTIVE_OVERLAP_STATUSES)
            ->whereNull('material_requests.deleted_at')
            ->whereDate('material_requests.needed_from', '<=', $to->copy()->timezone(BookingSchedule::TIMEZONE)->addDay()->toDateString())
            ->whereDate('material_requests.return_by', '>=', $from->copy()->timezone(BookingSchedule::TIMEZONE)->subDay()->toDateString())
            ->when($excludeRequestId, fn ($query) => $query->where('material_requests.id', '!=', $excludeRequestId))
            ->get([
                'material_requests.id as request_id',
                'material_requests.status as request_status',
                'material_request_items.equipment_item_id',
                'material_requests.needed_from',
                'material_requests.needed_from_at',
                'material_requests.return_by',
                'material_requests.return_by_at',
            ]);
    }

    /**
     * @return array{en: string, kn: string}
     */
    private function overlapMessage(string $name, int $existing, int $position): array
    {
        if ($position > self::MAX_ACTIVE_OVERLAPS) {
            return [
                'en' => $name.' already has '.self::MAX_ACTIVE_OVERLAPS.' active requests on these dates, including requests that are still pending. Choose other dates or another item.',
                'kn' => $name.' ifite ubusabe '.self::MAX_ACTIVE_OVERLAPS.' bukora muri iyi minsi, harimo n\'ibitaremewe. Hitamo indi tariki cyangwa ikindi gikoresho.',
            ];
        }
        if ($existing === 0) {
            return [
                'en' => 'Your booking for '.$name.' is pending a decision and is not confirmed yet.',
                'kn' => 'Ubusabe bwawe bwa '.$name.' burategereje icyemezo kandi ntiburabonezwa.',
            ];
        }

        $noun = $existing === 1 ? 'request' : 'requests';

        return [
            'en' => 'There '.($existing === 1 ? 'is' : 'are').' already '.$existing.' active '.$noun.' for '.$name.' on overlapping dates. This booking can wait, but it is not guaranteed until LIA decides.',
            'kn' => 'Hari ubusabe '.$existing.' bukora kuri '.$name.' mu minsi ihuriye. Ubu ubusabe bwawe burashobora gutegereza, ariko ntibwizewe kugeza LIA iyemeza.',
        ];
    }

    /**
     * @return array{en: string, kn: string}
     */
    private function overlapSummary(string $level): array
    {
        if ($level === 'not_guaranteed') {
            return [
                'en' => 'Other requests already cover these dates. This booking can wait, but it is not guaranteed until LIA decides.',
                'kn' => 'Hari ubundi busabe buhuriye n\'iyi minsi. Ubu ubusabe bwawe burashobora gutegereza, ariko ntibwizewe kugeza LIA iyemeza.',
            ];
        }

        return [
            'en' => 'Your booking is pending a decision and is not confirmed yet.',
            'kn' => 'Ubusabe bwawe burategereje icyemezo kandi ntiburabonezwa.',
        ];
    }

    private function rangesOverlap(string $start, string $end, string $otherStart, string $otherEnd): bool
    {
        return $start !== '' && $end !== '' && $start <= $otherEnd && $end >= $otherStart;
    }

    private function dateString(mixed $value): string
    {
        $text = $value instanceof DateTimeInterface ? $value->format('Y-m-d') : (string) $value;

        return substr($text, 0, 10);
    }

    /**
     * @param  list<int>  $itemIds
     * @return array<int, array<string, int>>
     */
    private function reservedByItemAndDate(array $itemIds, CarbonImmutable $from, CarbonImmutable $to, ?int $excludeRequestId = null, ?CarbonImmutable $windowStart = null, ?CarbonImmutable $windowEnd = null): array
    {
        if ($itemIds === []) {
            return [];
        }

        $rows = MaterialRequestItem::query()
            ->join('material_requests', 'material_requests.id', '=', 'material_request_items.material_request_id')
            ->whereIn('material_request_items.equipment_item_id', $itemIds)
            ->whereIn('material_requests.status', ['approved', 'issued'])
            ->whereNull('material_requests.deleted_at')
            ->whereDate('material_requests.needed_from', '<=', $to->copy()->timezone(BookingSchedule::TIMEZONE)->addDay()->toDateString())
            ->whereDate('material_requests.return_by', '>=', $from->copy()->timezone(BookingSchedule::TIMEZONE)->subDay()->toDateString())
            ->when($excludeRequestId, fn ($query) => $query->where('material_requests.id', '!=', $excludeRequestId))
            ->get([
                'material_request_items.equipment_item_id',
                'material_request_items.qty_requested',
                'material_request_items.qty_approved',
                'material_request_items.qty_issued',
                'material_requests.needed_from',
                'material_requests.needed_from_at',
                'material_requests.return_by',
                'material_requests.return_by_at',
            ]);

        $reserved = [];
        $windowStart ??= $this->schedule->dayStart($from->toDateString());
        $windowEnd ??= $this->schedule->dayStart($to->toDateString())->addDay();

        foreach ($rows as $row) {
            $qty = $row->qty_issued !== null
                ? (int) $row->qty_issued
                : (int) ($row->qty_approved ?? $row->qty_requested);
            if ($qty <= 0) {
                continue;
            }
            [$rowStart, $rowEnd] = $this->rowInterval($row);
            if (! $this->schedule->overlaps($windowStart, $windowEnd, $rowStart, $rowEnd)) {
                continue;
            }

            $start = $this->bookingDay($row->needed_from);
            $end = $this->bookingDay($row->return_by);
            if ($start->toDateString() < $from->toDateString()) {
                $start = $this->bookingDay($from->toDateString());
            }
            if ($end->toDateString() > $to->toDateString()) {
                $end = $this->bookingDay($to->toDateString());
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
