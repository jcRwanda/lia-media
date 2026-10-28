<?php

namespace App\Console\Commands;

use App\Storage\ObjectStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Equipment photos and pickup/return proofs used to be written to the local "public" disk while
 * their URLs are presigned against object storage (R2), so they rendered as broken images.
 * This copies any such file into object storage under the same key.
 */
class MigrateLocalImagesToObjectStorageCommand extends Command
{
    protected $signature = 'media:migrate-local-images {--dry-run}';

    protected $description = 'Copy equipment images and booking proofs from the local public disk into object storage';

    public function handle(ObjectStorage $storage): int
    {
        $paths = collect()
            ->merge(DB::table('equipment_item_images')->pluck('path'))
            ->merge(DB::table('equipment_items')->whereNotNull('photo_path')->pluck('photo_path'))
            ->merge(DB::table('material_request_item_proofs')->pluck('path'))
            ->filter()->unique()->values();

        $disk = Storage::disk('public');
        $copied = $present = $missing = 0;

        foreach ($paths as $path) {
            if ($storage->head($path) !== null) {
                $present++;

                continue;
            }
            if (! $disk->exists($path) || $disk->size($path) === 0) {
                $missing++;
                $this->warn("missing: {$path}");

                continue;
            }
            if (! $this->option('dry-run')) {
                $storage->put($path, $disk->get($path), $disk->mimeType($path) ?: 'application/octet-stream');
            }
            $copied++;
        }

        $this->info("copied={$copied} already_present={$present} missing={$missing}".($this->option('dry-run') ? ' (dry run)' : ''));

        return $missing > 0 ? self::FAILURE : self::SUCCESS;
    }
}
