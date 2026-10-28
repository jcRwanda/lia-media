<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EquipmentItem;
use App\Services\MaterialRequestService;
use App\Models\EquipmentItemImage;
use App\Storage\ObjectStorage;
use App\Support\ImageNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

class EquipmentItemController extends Controller
{
    private const IMAGE_MIMES = 'jpg,jpeg,png,webp,heic,heif';

    private const MAX_KB = 20480;

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
            'photo' => ['nullable', 'file', 'mimes:'.self::IMAGE_MIMES, 'max:'.self::MAX_KB],
            'photos' => ['nullable', 'array'],
            'photos.*' => ['nullable', 'file', 'mimes:'.self::IMAGE_MIMES, 'max:'.self::MAX_KB],
            'images' => ['nullable', 'array'],
            'images.*' => ['nullable', 'file', 'mimes:'.self::IMAGE_MIMES, 'max:'.self::MAX_KB],
            'accessory_ids' => ['nullable', 'array'],
            'accessory_ids.*' => ['integer', 'exists:equipment_items,id'],
            'sync_accessories' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        unset($data['photo'], $data['photos'], $data['images'], $data['accessory_ids'], $data['sync_accessories']);

        if ($request->hasFile('photo')) {
            $data['photo_path'] = $this->storeFile($request->file('photo'))['path'];
        }

        $item = EquipmentItem::query()->create($data);

        $this->storeImages($request, $item);
        $this->syncAccessories($request, $item);

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
            'photo' => ['nullable', 'file', 'mimes:'.self::IMAGE_MIMES, 'max:'.self::MAX_KB],
            'photos' => ['nullable', 'array'],
            'photos.*' => ['nullable', 'file', 'mimes:'.self::IMAGE_MIMES, 'max:'.self::MAX_KB],
            'images' => ['nullable', 'array'],
            'images.*' => ['nullable', 'file', 'mimes:'.self::IMAGE_MIMES, 'max:'.self::MAX_KB],
            'accessory_ids' => ['nullable', 'array'],
            'accessory_ids.*' => ['integer', 'exists:equipment_items,id'],
            'sync_accessories' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        unset($data['photo'], $data['photos'], $data['images'], $data['accessory_ids'], $data['sync_accessories']);

        if (array_key_exists('serial_number', $data)
            && (string) ($data['serial_number'] ?? '') !== (string) ($equipmentItem->serial_number ?? '')
            && ! $request->user()->canDo('equipment.edit_serial')) {
            return response()->json([
                'message' => 'Only an admin can change the serial number.',
                'errors' => ['serial_number' => ['Only an admin can change the serial number.']],
            ], 403);
        }
        if (! $request->user()->canDo('equipment.edit_serial')) {
            unset($data['serial_number']);
        }

        if ($request->hasFile('photo')) {
            $data['photo_path'] = $this->storeFile($request->file('photo'))['path'];
        }

        $equipmentItem->update($data);
        $this->storeImages($request, $equipmentItem);
        $this->syncAccessories($request, $equipmentItem);

        return response()->json(['item' => $this->present($equipmentItem->fresh()->load('images'))]);
    }

    private function syncAccessories(Request $request, EquipmentItem $item): void
    {
        if (! $request->has('accessory_ids') && ! $request->boolean('sync_accessories')) {
            return;
        }
        $ids = collect((array) $request->input('accessory_ids', []))->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0 && $id !== $item->id)->unique()->values()->all();
        $item->accessories()->sync($ids);
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

        if ($files !== []) {
            $this->adoptLegacyPhoto($item);
        }
        $next = ((int) $item->images()->max('sort_order')) + 1;
        foreach ($files as $file) {
            if (! $file || ! $file->isValid()) {
                continue;
            }
            $stored = $this->storeFile($file);
            $path = $stored['path'];
            $item->images()->create([
                'path' => $path,
                'name' => $stored['name'],
                'mime' => $stored['mime'],
                'sort_order' => $next++,
            ]);
            if (! $item->photo_path) {
                $item->update(['photo_path' => $path]);
            }
        }
    }

    /** Items created before the gallery only have photo_path: turn it into a managed image row. */
    private function adoptLegacyPhoto(EquipmentItem $item): void
    {
        if ($item->photo_path && ! $item->images()->where('path', $item->photo_path)->exists()) {
            $item->images()->increment('sort_order');
            $item->images()->create([
                'path' => $item->photo_path,
                'name' => basename($item->photo_path),
                'mime' => null,
                'sort_order' => 0,
            ]);
        }
    }

    /** Re-number images in the given order; the first one becomes the main photo. */
    private function applyOrder(EquipmentItem $item, array $ids): void
    {
        $images = $item->images()->get()->keyBy('id');
        $ordered = collect($ids)->map(fn ($id) => (int) $id)->filter(fn ($id) => $images->has($id))->unique()->values();
        $rest = $images->keys()->diff($ordered);
        foreach ($ordered->concat($rest)->values() as $pos => $id) {
            $images[$id]->update(['sort_order' => $pos]);
        }
        $first = $item->images()->first();
        $item->update(['photo_path' => $first?->path]);
    }

    public function reorderImages(Request $request, EquipmentItem $equipmentItem): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer']]);
        $this->adoptLegacyPhoto($equipmentItem);
        $this->applyOrder($equipmentItem, $data['ids']);

        return response()->json(['item' => $this->present($equipmentItem->fresh()->load('images'))]);
    }

    public function setMainImage(EquipmentItem $equipmentItem, EquipmentItemImage $image): JsonResponse
    {
        abort_unless($image->equipment_item_id === $equipmentItem->id, 404);
        $this->adoptLegacyPhoto($equipmentItem);
        $ids = $equipmentItem->images()->pluck('id')->reject(fn ($id) => $id === $image->id)->prepend($image->id)->all();
        $this->applyOrder($equipmentItem, $ids);

        return response()->json(['item' => $this->present($equipmentItem->fresh()->load('images'))]);
    }

    public function destroyImage(EquipmentItem $equipmentItem, EquipmentItemImage $image, ObjectStorage $storage): JsonResponse
    {
        abort_unless($image->equipment_item_id === $equipmentItem->id, 404);
        $path = $image->path;
        $image->delete();
        $stillUsed = EquipmentItemImage::query()->where('path', $path)->exists()
            || EquipmentItem::query()->where('photo_path', $path)->where('id', '!=', $equipmentItem->id)->exists();
        if (! $stillUsed) {
            try {
                $storage->delete($path);
            } catch (\Throwable) {
                // object already gone; the row is what matters
            }
        }
        $this->applyOrder($equipmentItem, $equipmentItem->images()->pluck('id')->all());

        return response()->json(['item' => $this->present($equipmentItem->fresh()->load('images'))]);
    }

    /** @return array{path: string, name: string, mime: string} */
    private function storeFile(UploadedFile $file): array
    {
        return ImageNormalizer::store($file, 'material-requests/equipment');
    }

    public function destroy(Request $request, EquipmentItem $equipmentItem): JsonResponse
    {
        if (! $request->user()?->canDo('equipment.delete')) {
            abort(403, 'Only an admin can delete equipment.');
        }

        $equipmentItem->delete();

        return response()->json(['message' => 'Deleted']);
    }

    private function present(EquipmentItem $item): array
    {
        $payload = $item->toArray();
        $user = request()->user();
        $payload['qr_url'] = $item->qr_token ? $item->qrUrl() : null;
        $payload['can_edit_serial'] = (bool) $user?->canDo('equipment.edit_serial');
        $payload['can_delete'] = (bool) $user?->canDo('equipment.delete');
        $payload['photo_url'] = $this->files->presign($item->photo_path, 'equipment-'.$item->id);
        $accessories = $item->accessories()->get(['equipment_items.id', 'equipment_items.name', 'equipment_items.allowed_for_trainees']);
        $payload['accessory_ids'] = $accessories->pluck('id')->values()->all();
        $payload['accessories'] = $accessories->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'allowed_for_trainees' => (bool) $a->allowed_for_trainees])->values()->all();
        $payload['images'] = $item->images->map(fn ($image) => [
            'id' => $image->id,
            'name' => $image->name,
            'mime' => $image->mime,
            'url' => $this->files->presign($image->path, $image->name),
            'is_main' => $image->path === $item->photo_path,
        ])->values()->all();

        return $payload;
    }
}
