<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Items with only a legacy photo_path get a managed image row so it can be removed or reordered. */
    public function up(): void
    {
        $items = DB::table('equipment_items')->whereNotNull('photo_path')->where('photo_path', '!=', '')->get(['id', 'photo_path']);
        foreach ($items as $item) {
            $exists = DB::table('equipment_item_images')->where('equipment_item_id', $item->id)->where('path', $item->photo_path)->exists();
            if ($exists) {
                continue;
            }
            DB::table('equipment_item_images')->where('equipment_item_id', $item->id)->increment('sort_order');
            DB::table('equipment_item_images')->insert([
                'equipment_item_id' => $item->id,
                'path' => $item->photo_path,
                'name' => basename($item->photo_path),
                'mime' => null,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // data-only migration; nothing to undo
    }
};
