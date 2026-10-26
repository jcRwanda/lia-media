<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EquipmentItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EquipmentItemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()?->canDo('equipment.manage') && ! $request->user()?->canDo('material-requests.view')) {
            abort(403);
        }

        $items = EquipmentItem::query()->orderBy('name')->get();

        return response()->json(['items' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()?->canDo('equipment.manage')) {
            abort(403);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'asset_tag' => ['nullable', 'string', 'max:255'],
            'condition' => ['nullable', 'string', 'max:255'],
            'total_qty' => ['required', 'integer', 'min:0'],
            'allowed_for_trainees' => ['nullable', 'boolean'],
            'photo' => ['nullable', 'image', 'max:5120'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        unset($data['photo']);

        if ($request->hasFile('photo')) {
            $path = $request->file('photo')->store('material-requests/equipment', 'public');
            $data['photo_path'] = $path;
        }

        $item = EquipmentItem::query()->create($data);

        return response()->json(['item' => $item], 201);
    }

    public function show(Request $request, EquipmentItem $equipmentItem): JsonResponse
    {
        if (! $request->user()?->canDo('equipment.manage') && ! $request->user()?->canDo('material-requests.view')) {
            abort(403);
        }

        return response()->json(['item' => $equipmentItem]);
    }

    public function update(Request $request, EquipmentItem $equipmentItem): JsonResponse
    {
        if (! $request->user()?->canDo('equipment.manage')) {
            abort(403);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'asset_tag' => ['nullable', 'string', 'max:255'],
            'condition' => ['nullable', 'string', 'max:255'],
            'total_qty' => ['sometimes', 'integer', 'min:0'],
            'allowed_for_trainees' => ['nullable', 'boolean'],
            'photo' => ['nullable', 'image', 'max:5120'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        unset($data['photo']);

        if ($request->hasFile('photo')) {
            $path = $request->file('photo')->store('material-requests/equipment', 'public');
            $data['photo_path'] = $path;
        }

        $equipmentItem->update($data);

        return response()->json(['item' => $equipmentItem->fresh()]);
    }

    public function destroy(Request $request, EquipmentItem $equipmentItem): JsonResponse
    {
        if (! $request->user()?->canDo('equipment.manage')) {
            abort(403);
        }

        $equipmentItem->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
