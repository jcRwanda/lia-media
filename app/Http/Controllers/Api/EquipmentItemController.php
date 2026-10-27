<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EquipmentItem;
use App\Services\MaterialRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class EquipmentItemController extends Controller
{
    public function __construct(private MaterialRequestService $files) {}

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()?->canDo('equipment.manage') && ! $request->user()?->canDo('material-requests.view')) {
            abort(403);
        }

        $items = EquipmentItem::query()->with('images')->orderBy('name')->get();

        return response()->json(['items' => $items->map(fn (EquipmentItem $item) => $this->present($item))->values()]);
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
            'photos' => ['nullable', 'array'],
            'photos.*' => ['image', 'max:10240'],
            'images' => ['nullable', 'array'],
            'images.*' => ['image', 'max:10240'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        unset($data['photo'], $data['photos'], $data['images']);

        if ($request->hasFile('photo')) {
            $path = $request->file('photo')->store('material-requests/equipment', 'public');
            $data['photo_path'] = $path;
        }

        $item = EquipmentItem::query()->create($data);

        $this->storeImages($request, $item);

        return response()->json(['item' => $this->present($item->load('images'))], 201);
    }

    public function show(Request $request, EquipmentItem $equipmentItem): JsonResponse
    {
        if (! $request->user()?->canDo('equipment.manage') && ! $request->user()?->canDo('material-requests.view')) {
            abort(403);
        }

        return response()->json(['item' => $this->present($equipmentItem->load('images'))]);
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
            'photos' => ['nullable', 'array'],
            'photos.*' => ['image', 'max:10240'],
            'images' => ['nullable', 'array'],
            'images.*' => ['image', 'max:10240'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        unset($data['photo'], $data['photos'], $data['images']);

        if ($request->hasFile('photo')) {
            $path = $request->file('photo')->store('material-requests/equipment', 'public');
            $data['photo_path'] = $path;
        }

        $equipmentItem->update($data);
        $this->storeImages($request, $equipmentItem);

        return response()->json(['item' => $this->present($equipmentItem->fresh()->load('images'))]);
    }

    private function storeImages(Request $request, EquipmentItem $item): void
    {
        $files = [];
        if ($request->hasFile('photos')) {
            $photos = $request->file('photos');
            if (is_array($photos)) {
                $files = array_merge($files, $photos);
            } else {
                $files[] = $photos;
            }
        }
        if ($request->hasFile('images')) {
            $images = $request->file('images');
            if (is_array($images)) {
                $files = array_merge($files, $images);
            } else {
                $files[] = $images;
            }
        }

        foreach ($files as $idx => $file) {
            if (! $file || ! $file->isValid()) {
                continue;
            }
            $path = $file->store('material-requests/equipment', 'public');
            $item->images()->create([
                'path' => $path,
                'name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType(),
                'sort_order' => $item->images()->count() + $idx,
            ]);
            if (! $item->photo_path) {
                $item->update(['photo_path' => $path]);
            }
        }
    }

    public function destroy(Request $request, EquipmentItem $equipmentItem): JsonResponse
    {
        if (! $request->user()?->canDo('equipment.manage')) {
            abort(403);
        }

        $equipmentItem->delete();

        return response()->json(['message' => 'Deleted']);
    }

    private function present(EquipmentItem $item): array
    {
        $payload = $item->toArray();
        $payload['photo_url'] = $this->files->presign($item->photo_path, 'equipment-'.$item->id);
        $payload['images'] = $item->images->map(fn ($image) => [
            'id' => $image->id,
            'name' => $image->name,
            'mime' => $image->mime,
            'url' => $this->files->presign($image->path, $image->name),
        ])->values()->all();

        return $payload;
    }
}
