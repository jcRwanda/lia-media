<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EquipmentAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MaterialRequestAvailabilityController extends Controller
{
    public function __construct(private EquipmentAvailabilityService $availability) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_ids' => ['nullable', 'array', 'min:1', 'max:100'],
            'item_ids.*' => ['required', 'integer', Rule::exists('equipment_items', 'id')->whereNull('deleted_at')],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'type' => ['nullable', Rule::in(config('material-requests.requester_types', ['client', 'trainee', 'alumni']))],
        ]);

        $from = isset($data['from'])
            ? CarbonImmutable::parse($data['from'])->startOfDay()
            : CarbonImmutable::today();
        $to = isset($data['to'])
            ? CarbonImmutable::parse($data['to'])->startOfDay()
            : $from->addDays(90);

        if ((int) $from->diffInDays($to) > 366) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => ['to' => ['The availability range may not exceed 366 days.']],
            ], 422);
        }

        $itemIds = [];
        if (isset($data['item_ids'])) {
            $itemIds = array_values(array_map('intval', $data['item_ids']));
            if (($data['type'] ?? null) === 'trainee') {
                $itemIds = \App\Models\EquipmentItem::query()
                    ->whereIn('id', $itemIds)
                    ->where('allowed_for_trainees', true)
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            }
        } elseif (($data['type'] ?? null) === 'trainee') {
            $itemIds = \App\Models\EquipmentItem::query()
                ->where('allowed_for_trainees', true)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return response()->json($this->availability->availability($itemIds, $from, $to));
    }
}
