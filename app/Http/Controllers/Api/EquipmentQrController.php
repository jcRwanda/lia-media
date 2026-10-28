<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EquipmentCheck;
use App\Models\EquipmentItem;
use App\Models\MaterialRequest;
use App\Services\MaterialRequestAuditService;
use App\Services\MaterialRequestService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EquipmentQrController extends Controller
{
    public function __construct(
        private MaterialRequestService $files,
        private MaterialRequestAuditService $audit,
    ) {}

    private function resolve(string $token): EquipmentItem
    {
        return EquipmentItem::query()->where('qr_token', $token)->with('images')->firstOrFail();
    }

    /** Approved/issued bookings that contain this item and are current (around today). */
    public static function currentBookings(EquipmentItem $item): Collection
    {
        $from = now()->addDay()->toDateString();
        $to = now()->subDay()->toDateString();

        return MaterialRequest::query()
            ->whereIn('status', ['approved', 'issued'])
            ->whereHas('items', fn ($q) => $q->where('equipment_item_id', $item->id))
            ->where(function ($q) use ($from, $to) {
                $q->where('status', 'issued')->orWhere(function ($q) use ($from, $to) {
                    $q->where(fn ($q) => $q->whereNull('needed_from')->orWhereDate('needed_from', '<=', $from))
                        ->where(fn ($q) => $q->whereNull('return_by')->orWhereDate('return_by', '>=', $to));
                });
            })
            ->with('items')
            ->orderBy('needed_from')
            ->get();
    }

    private static function digits(?string $v): string
    {
        $d = preg_replace('/\D+/', '', (string) $v);

        return strlen($d) > 9 ? substr($d, -9) : $d;
    }

    private function photoUrl(EquipmentItem $item): ?string
    {
        $path = $item->photo_path ?: $item->images->first()?->path;

        return $path ? $this->files->presign($path, 'equipment-'.$item->id) : null;
    }

    // ---- Public (borrower) ----

    public function publicShow(string $token): JsonResponse
    {
        $item = $this->resolve($token);
        $serial = (string) $item->serial_number;

        return response()->json(['item' => [
            'name' => $item->name,
            'category' => $item->category,
            'serial_hint' => $serial !== '' ? str_repeat('•', max(0, strlen($serial) - 4)).substr($serial, -4) : null,
            'photo_url' => $this->photoUrl($item),
        ]]);
    }

    public function confirm(Request $request, string $token): JsonResponse
    {
        $item = $this->resolve($token);
        $data = $request->validate(['contact' => ['required', 'string', 'max:255']]);
        $contact = trim($data['contact']);
        $isEmail = str_contains($contact, '@');

        $booking = self::currentBookings($item)->first(function (MaterialRequest $mr) use ($contact, $isEmail) {
            if ($isEmail) {
                return $mr->email && strcasecmp(trim($mr->email), $contact) === 0;
            }
            $d = self::digits($contact);

            return strlen($d) >= 7 && $d === self::digits($mr->phone);
        });

        if (! $booking) {
            return response()->json([
                'message' => 'We could not find a confirmed booking for this item with that phone number or email.',
                'errors' => ['contact' => ['No matching booking found for this item.']],
            ], 422);
        }

        $line = $booking->items->firstWhere('equipment_item_id', $item->id);
        $existing = EquipmentCheck::query()->where('type', EquipmentCheck::PICKUP_CONFIRM)
            ->where('material_request_item_id', $line?->id)->first();

        if (! $existing) {
            $existing = EquipmentCheck::query()->create([
                'equipment_item_id' => $item->id,
                'material_request_id' => $booking->id,
                'material_request_item_id' => $line?->id,
                'type' => EquipmentCheck::PICKUP_CONFIRM,
                'borrower_name' => $booking->full_name,
                'verified_via' => $isEmail ? 'email' : 'phone',
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            ]);
            $this->audit->log($booking, 'equipment.pickup_confirmed', [
                'equipment_item_id' => $item->id,
                'equipment_name' => $item->name,
                'verified_via' => $existing->verified_via,
            ], null, 'requester');
        }

        return response()->json([
            'confirmed' => true,
            'confirmed_at' => $existing->created_at?->toIso8601String(),
            'booking' => [
                'reference_code' => $booking->reference_code,
                'full_name' => $booking->full_name,
                'needed_from' => $booking->needed_from?->toDateString(),
                'return_by' => $booking->return_by?->toDateString(),
            ],
        ]);
    }

    // ---- Staff (storekeeper) ----

    public function scan(Request $request, string $token): JsonResponse
    {
        $item = $this->resolve($token);

        $bookings = self::currentBookings($item)->map(function (MaterialRequest $mr) use ($item) {
            $line = $mr->items->firstWhere('equipment_item_id', $item->id);

            return [
                'id' => $mr->id,
                'reference_code' => $mr->reference_code,
                'full_name' => $mr->full_name,
                'phone' => $mr->phone,
                'status' => $mr->status,
                'needed_from' => $mr->needed_from?->toDateString(),
                'return_by' => $mr->return_by?->toDateString(),
                'item_line' => $line ? [
                    'id' => $line->id, 'status' => $line->status, 'qty_approved' => $line->qty_approved,
                    'qty_issued' => $line->qty_issued, 'issue_condition' => $line->issue_condition,
                ] : null,
                'pickup_confirmed' => $line ? EquipmentCheck::query()->where('material_request_item_id', $line->id)
                    ->where('type', EquipmentCheck::PICKUP_CONFIRM)->exists() : false,
            ];
        })->values();

        return response()->json([
            'item' => [
                'id' => $item->id,
                'name' => $item->name,
                'category' => $item->category,
                'serial_number' => $item->serial_number,
                'asset_tag' => $item->asset_tag,
                'condition' => $item->condition,
                'photo_url' => $this->photoUrl($item),
                'images' => $item->images->map(fn ($i) => ['id' => $i->id, 'url' => $this->files->presign($i->path, $i->name)])->values(),
            ],
            'bookings' => $bookings,
            'recent_checks' => $item->checks()->with('actor:id,name')->latest()->limit(10)->get()->map->present()->values(),
        ]);
    }

    public function check(Request $request, string $token): JsonResponse
    {
        $item = $this->resolve($token);
        $data = $request->validate([
            'type' => ['required', Rule::in([EquipmentCheck::ISSUE_CHECK, EquipmentCheck::RETURN_CHECK])],
            'material_request_id' => ['nullable', 'integer', 'exists:material_requests,id'],
            'serial_matches' => ['required', 'boolean'],
            'serial_seen' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $booking = isset($data['material_request_id']) ? MaterialRequest::query()->with('items')->find($data['material_request_id']) : null;
        $line = $booking?->items->firstWhere('equipment_item_id', $item->id);

        $reasons = [];
        if (! $data['serial_matches']) {
            $reasons[] = 'Serial number does not match the stored record';
        }
        if (! $booking) {
            $reasons[] = 'No booking selected for this item';
        } elseif (! $line) {
            $reasons[] = 'Item is not on this booking';
        } elseif (! self::currentBookings($item)->contains('id', $booking->id)) {
            $reasons[] = 'Booking is not current';
        }

        $check = EquipmentCheck::query()->create([
            'equipment_item_id' => $item->id,
            'material_request_id' => $booking?->id,
            'material_request_item_id' => $line?->id,
            'type' => $data['type'],
            'actor_id' => $request->user()->id,
            'borrower_name' => $booking?->full_name,
            'serial_matches' => $data['serial_matches'],
            'serial_seen' => $data['serial_seen'] ?? null,
            'notes' => $data['notes'] ?? null,
            'flagged' => $reasons !== [],
            'flag_reason' => $reasons ? implode('; ', $reasons) : null,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        if ($booking) {
            $this->audit->log($booking, 'equipment.'.$data['type'], [
                'equipment_item_id' => $item->id,
                'equipment_name' => $item->name,
                'serial_matches' => $data['serial_matches'],
                'flagged' => $check->flagged,
                'flag_reason' => $check->flag_reason,
                'notes' => $check->notes,
            ]);
        }

        return response()->json(['check' => $check->load('actor:id,name')->present()], 201);
    }
}
