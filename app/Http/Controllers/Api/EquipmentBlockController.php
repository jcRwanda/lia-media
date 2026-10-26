<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EquipmentBlock;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EquipmentBlockController extends Controller
{
    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'equipment_item_id' => ['nullable', 'integer', Rule::exists('equipment_items', 'id')->whereNull('deleted_at')],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $block = EquipmentBlock::query()->create([
            ...$data,
            'created_by' => $request->user()?->id,
        ]);

        $audit->log('equipment.blocked', $block, [
            'equipment_item_id' => $block->equipment_item_id,
            'starts_on' => $block->starts_on?->format('Y-m-d'),
            'ends_on' => $block->ends_on?->format('Y-m-d'),
            'reason' => $block->reason,
        ]);

        return response()->json([
            'block' => $block->load('equipmentItem:id,name'),
        ], 201);
    }

    public function destroy(Request $request, EquipmentBlock $equipmentBlock, AuditLogger $audit): JsonResponse
    {
        $audit->log('equipment.block_removed', $equipmentBlock, [
            'equipment_item_id' => $equipmentBlock->equipment_item_id,
            'starts_on' => $equipmentBlock->starts_on?->format('Y-m-d'),
            'ends_on' => $equipmentBlock->ends_on?->format('Y-m-d'),
            'reason' => $equipmentBlock->reason,
        ]);

        $equipmentBlock->delete();

        return response()->json(['message' => 'Block removed']);
    }
}
