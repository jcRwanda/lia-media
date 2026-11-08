<?php

namespace App\Services;

use App\Models\MaterialRequest;
use App\Models\MaterialRequestItem;
use App\Support\BookingSchedule;
use Carbon\CarbonImmutable;

/**
 * Live hand-out and return obligations for staff. Nothing is stored, sent, or dismissed.
 * A booking leaves the list only when the equipment is actually issued or returned.
 */
class OperationalReminderService
{
    public const WINDOW_HOURS = 72;

    public function __construct(private BookingSchedule $schedule) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now(BookingSchedule::TIMEZONE))->timezone(BookingSchedule::TIMEZONE);
        $horizon = $now->addHours(self::WINDOW_HOURS);
        $requests = MaterialRequest::query()
            ->with('items.equipmentItem:id,name,serial_number')
            ->whereIn('status', ['submitted', 'changes_requested', 'approved', 'issued', 'returned'])
            ->get();

        $upcomingPickups = [];
        $overduePickups = [];
        $returnsDue = [];
        $overdueReturns = [];
        $pending = [];

        foreach ($requests as $request) {
            $pickup = $this->moment($request->needed_from, $request->needed_from_at, endOfDay: false);
            $due = $this->moment($request->return_by, $request->return_by_at, endOfDay: true);
            $toIssue = $this->lines($request, 'issue');
            $toReturn = $this->lines($request, 'return');

            if (in_array($request->status, ['submitted', 'changes_requested'], true) && $pickup && $this->inWindow($pickup, $now, $horizon, false)) {
                $pending[] = $this->card($request, 'pending_approval', $pickup, $now, $toIssue !== [] ? $toIssue : $this->lines($request, 'requested'), false);
            }

            if (in_array($request->status, ['approved', 'issued'], true) && $toIssue !== [] && $pickup && $this->inWindow($pickup, $now, $horizon, false)) {
                $card = $this->card($request, 'pickup', $pickup, $now, $toIssue, false);
                if ($card['urgency'] === 'overdue') {
                    $overduePickups[] = $card;
                } else {
                    $upcomingPickups[] = $card;
                }
            }

            if (in_array($request->status, ['issued', 'returned'], true) && $toReturn !== [] && $due && $this->inWindow($due, $now, $horizon, true)) {
                $card = $this->card($request, 'return', $due, $now, $toReturn, true);
                if ($card['urgency'] === 'overdue') {
                    $overdueReturns[] = $card;
                } else {
                    $returnsDue[] = $card;
                }
            }
        }

        $sort = fn (array $cards) => collect($cards)->sortBy('at')->values()->all();

        return [
            'timezone' => BookingSchedule::TIMEZONE,
            'utc_offset' => '+02:00',
            'window_hours' => self::WINDOW_HOURS,
            'as_of' => $now->toIso8601String(),
            'horizon' => $horizon->toIso8601String(),
            'generated_from' => 'live_booking_state',
            'dismiss_supported' => false,
            'channels' => ['in_app' => true, 'email' => false, 'sms' => false, 'whatsapp' => false],
            'upcoming_pickups' => $sort($upcomingPickups),
            'overdue_pickups' => $sort($overduePickups),
            'returns_due' => $sort($returnsDue),
            'overdue_returns' => $sort($overdueReturns),
            'pending_approval' => $sort($pending),
        ];
    }

    /**
     * @return array{at: CarbonImmutable, time_specified: bool, label: string}|null
     */
    private function moment(mixed $date, mixed $at, bool $endOfDay): ?array
    {
        if ($at) {
            $instant = CarbonImmutable::parse($at)->utc()->timezone(BookingSchedule::TIMEZONE);
        } elseif ($date) {
            $day = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : substr((string) $date, 0, 10);
            $instant = $endOfDay
                ? $this->schedule->dayStart($day)->addDay()->timezone(BookingSchedule::TIMEZONE)
                : $this->schedule->dayStart($day)->timezone(BookingSchedule::TIMEZONE);
        } else {
            return null;
        }

        $presented = $this->schedule->present($date, $at);

        return [
            'at' => $instant,
            'time_specified' => $presented['time_specified'],
            'label' => (string) $presented['label'],
        ];
    }

    /**
     * @return list<array{name: string, qty: int, serial: ?string}>
     */
    private function lines(MaterialRequest $request, string $kind): array
    {
        return $request->items->map(function (MaterialRequestItem $item) use ($kind) {
            $approved = (int) ($item->qty_approved ?? $item->qty_requested ?? 0);
            $issued = (int) ($item->qty_issued ?? 0);
            $returned = (int) ($item->qty_returned ?? 0);
            $qty = match ($kind) {
                'issue' => max(0, $approved - $issued),
                'return' => max(0, $issued - $returned),
                default => max(1, (int) ($item->qty_requested ?? 1)),
            };
            if ($qty <= 0) {
                return null;
            }

            return [
                'name' => $item->equipmentItem?->name ?? 'Item',
                'qty' => $qty,
                'serial' => $item->equipmentItem?->serial_number,
            ];
        })->filter()->values()->all();
    }

    /**
     * @param  array{at: CarbonImmutable, time_specified: bool, label: string}  $moment
     */
    private function inWindow(array $moment, CarbonImmutable $now, CarbonImmutable $horizon, bool $endOfDay): bool
    {
        if ($moment['time_specified']) {
            return $moment['at']->lte($horizon);
        }
        $day = $endOfDay ? $moment['at']->subDay()->toDateString() : $moment['at']->toDateString();
        if ($day < $now->toDateString()) {
            return true;
        }

        return $moment['at']->lte($horizon);
    }

    /**
     * @param  array{at: CarbonImmutable, time_specified: bool, label: string}  $moment
     * @param  list<array{name: string, qty: int, serial: ?string}>  $lines
     * @return array<string, mixed>
     */
    private function card(MaterialRequest $request, string $kind, array $moment, CarbonImmutable $now, array $lines, bool $endOfDay): array
    {
        $at = $moment['at'];
        if ($moment['time_specified']) {
            $urgency = $at->lt($now) ? 'overdue' : ($at->toDateString() === $now->toDateString() ? 'due_today' : 'within_3_days');
        } else {
            $day = $endOfDay ? $at->subDay()->toDateString() : $at->toDateString();
            $urgency = $day < $now->toDateString() ? 'overdue' : ($day === $now->toDateString() ? 'due_today' : 'within_3_days');
        }

        return [
            'id' => $request->id,
            'reference_code' => $request->reference_code,
            'borrower_name' => $request->full_name,
            'status' => $request->status,
            'kind' => $kind,
            'confirmed_handover' => $request->status === 'approved' || in_array($request->status, ['issued', 'returned'], true),
            'urgency' => $urgency,
            'at' => $at->toIso8601String(),
            'time_specified' => $moment['time_specified'],
            'label' => $moment['label'],
            'items' => $lines,
            'staff_url' => '/material-requests/'.$request->id,
            'action' => $kind === 'return' ? 'check_in' : ($kind === 'pending_approval' ? 'review' : 'hand_out'),
        ];
    }
}
