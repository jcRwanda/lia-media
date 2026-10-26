<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EquipmentBlock;
use App\Models\EquipmentItem;
use App\Models\MaterialRequest;
use App\Services\AuditLogger;
use App\Services\EquipmentAvailabilityService;
use App\Services\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class EquipmentCalendarController extends Controller
{
    public function __construct(
        private EquipmentAvailabilityService $availability,
        private SettingsService $settings,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'month' => ['nullable', 'date_format:Y-m'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $month = $validator->validated()['month'] ?? now()->format('Y-m');
        $start = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
        $end = $start->endOfMonth()->startOfDay();

        $bookings = MaterialRequest::query()
            ->with(['items.equipmentItem:id,name', 'apiClient:id,name'])
            ->whereIn('status', ['approved', 'issued'])
            ->whereDate('needed_from', '<=', $end->toDateString())
            ->whereDate('return_by', '>=', $start->toDateString())
            ->orderBy('needed_from')
            ->orderBy('id')
            ->get()
            ->map(fn (MaterialRequest $materialRequest) => [
                'id' => $materialRequest->id,
                'reference_code' => $materialRequest->reference_code,
                'status' => $materialRequest->status,
                'source' => $materialRequest->source,
                'source_detail' => $materialRequest->source_detail,
                'requester_type' => $materialRequest->requester_type,
                'needed_from' => $materialRequest->needed_from?->format('Y-m-d'),
                'return_by' => $materialRequest->return_by?->format('Y-m-d'),
                'items' => $materialRequest->items->map(fn ($item) => [
                    'equipment_item_id' => $item->equipment_item_id,
                    'name' => $item->equipmentItem?->name,
                    'qty_requested' => $item->qty_requested,
                    'qty_approved' => $item->qty_approved,
                    'qty_issued' => $item->qty_issued,
                ])->values(),
            ]);

        $blocks = EquipmentBlock::query()
            ->with(['equipmentItem:id,name', 'createdBy:id,name'])
            ->whereDate('starts_on', '<=', $end->toDateString())
            ->whereDate('ends_on', '>=', $start->toDateString())
            ->orderBy('starts_on')
            ->get()
            ->map(fn (EquipmentBlock $block) => [
                'id' => $block->id,
                'equipment_item_id' => $block->equipment_item_id,
                'item_name' => $block->equipmentItem?->name,
                'starts_on' => $block->starts_on?->format('Y-m-d'),
                'ends_on' => $block->ends_on?->format('Y-m-d'),
                'reason' => $block->reason,
                'created_by' => $block->createdBy?->name,
            ]);

        return response()->json([
            'month' => $month,
            'starts_on' => $start->toDateString(),
            'ends_on' => $end->toDateString(),
            'items' => EquipmentItem::query()->orderBy('name')->get(['id', 'name', 'total_qty']),
            'bookings' => $bookings,
            'blocks' => $blocks,
            'rules' => $this->availability->rules(),
        ]);
    }

    public function updateRules(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'closed_weekdays' => ['sometimes', 'array'],
            'closed_weekdays.*' => ['integer', Rule::in([0, 1, 2, 3, 4, 5, 6])],
            'max_booking_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'lead_time_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
        ]);

        $values = [];
        if (array_key_exists('closed_weekdays', $data)) {
            $values['equipment_closed_weekdays'] = array_values(array_unique(array_map('intval', $data['closed_weekdays'])));
        }
        if (array_key_exists('max_booking_days', $data)) {
            $values['equipment_max_booking_days'] = (int) $data['max_booking_days'];
        }
        if (array_key_exists('lead_time_days', $data)) {
            $values['equipment_lead_time_days'] = (int) $data['lead_time_days'];
        }

        $this->settings->put($values);
        $audit->log('equipment.rules_updated', null, $values);

        return response()->json(['rules' => $this->availability->rules()]);
    }
}
