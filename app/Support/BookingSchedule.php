<?php

namespace App\Support;

use App\Models\MaterialRequest;
use Carbon\CarbonImmutable;

/**
 * Scheduled pick-up and return instants in Africa/Kigali (UTC+2).
 * Date-only values stay date-only: the clock time is not invented.
 */
class BookingSchedule
{
    public const TIMEZONE = 'Africa/Kigali';

    public const OFFSET_LABEL = 'UTC+2';

    /**
     * @return array{date: string, at: ?CarbonImmutable, time_specified: bool}
     */
    public function parseInput(string $value): array
    {
        $value = trim($value);
        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) === 1) {
            return [
                'date' => $value,
                'at' => null,
                'time_specified' => false,
            ];
        }

        $hasOffset = preg_match('/(?:Z|[+-]\d{2}:?\d{2})\z/', $value) === 1;
        $instant = $hasOffset
            ? CarbonImmutable::parse($value)->utc()
            : CarbonImmutable::parse($value, self::TIMEZONE)->utc();

        return [
            'date' => $instant->timezone(self::TIMEZONE)->toDateString(),
            'at' => $instant,
            'time_specified' => true,
        ];
    }

    /**
     * @param  array{date: string, at: ?CarbonImmutable, time_specified: bool}  $start
     * @param  array{date: string, at: ?CarbonImmutable, time_specified: bool}  $end
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(array $start, array $end): array
    {
        return [
            $start['time_specified'] && $start['at'] ? $start['at'] : $this->dayStart($start['date']),
            $end['time_specified'] && $end['at'] ? $end['at'] : $this->dayStart($end['date'])->addDay(),
        ];
    }

    /**
     * Half-open interval for a stored booking. Legacy rows occupy each Kigali calendar day
     * from 00:00 until the next midnight, and are not given a fake clock time.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function rangeFor(MaterialRequest $request): array
    {
        return $this->range(
            [
                'date' => $request->needed_from?->format('Y-m-d') ?? '',
                'at' => $request->needed_from_at ? CarbonImmutable::parse($request->needed_from_at)->utc() : null,
                'time_specified' => $request->needed_from_at !== null,
            ],
            [
                'date' => $request->return_by?->format('Y-m-d') ?? '',
                'at' => $request->return_by_at ? CarbonImmutable::parse($request->return_by_at)->utc() : null,
                'time_specified' => $request->return_by_at !== null,
            ],
        );
    }

    /**
     * @param  array{date: string, at: ?CarbonImmutable, time_specified: bool}  $start
     * @param  array{date: string, at: ?CarbonImmutable, time_specified: bool}  $end
     */
    public function endsAfter(array $start, array $end): bool
    {
        [$from, $to] = $this->range($start, $end);

        return $to->gt($from);
    }

    public function overlaps(CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $otherStart, CarbonImmutable $otherEnd): bool
    {
        return $start->lt($otherEnd) && $otherStart->lt($end);
    }

    /**
     * @return array{date: ?string, at: ?string, time_specified: bool, timezone: string, utc_offset: string, label: ?string}
     */
    public function present(mixed $date, mixed $at): array
    {
        $day = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : ($date ? substr((string) $date, 0, 10) : null);
        $instant = $at ? CarbonImmutable::parse($at)->utc() : null;

        return [
            'date' => $day !== '' ? $day : null,
            'at' => $instant?->timezone(self::TIMEZONE)->toIso8601String(),
            'time_specified' => $instant !== null,
            'timezone' => self::TIMEZONE,
            'utc_offset' => '+02:00',
            'label' => $instant
                ? $instant->timezone(self::TIMEZONE)->format('j M Y, H:i').' (Africa/Kigali, UTC+2)'
                : ($day ? CarbonImmutable::parse($day, self::TIMEZONE)->format('j M Y').' (time not specified)' : null),
        ];
    }

    /**
     * @return array{needed_from: array<string, mixed>, return_by: array<string, mixed>, timezone: string}
     */
    public function presentRequest(MaterialRequest $request): array
    {
        return [
            'timezone' => self::TIMEZONE,
            'utc_offset' => '+02:00',
            'needed_from' => $this->present($request->needed_from, $request->needed_from_at),
            'return_by' => $this->present($request->return_by, $request->return_by_at),
            'submitted_at' => $request->created_at ? CarbonImmutable::parse($request->created_at)->utc()->toIso8601String() : null,
            'approved_at' => $request->approved_at ? CarbonImmutable::parse($request->approved_at)->utc()->toIso8601String() : null,
        ];
    }

    public function dayStart(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, self::TIMEZONE)->startOfDay()->utc();
    }
}
