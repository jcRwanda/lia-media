<?php

namespace Database\Seeders;

use App\Models\Album;
use App\Models\Asset;
use App\Models\AssetDerivative;
use App\Models\Event;
use App\Models\Project;
use App\Models\User;
use Aws\S3\S3Client;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * LOCAL ONLY. Seeds demo projects/albums/assets for screenshots.
 * Set DEMO_MEDIA_DIR to a folder of demoNN.webp + demoNN_thumb.webp files to also upload
 * them to the configured (local S3-compatible) bucket; otherwise only rows are created.
 */
class DemoContentSeeder extends Seeder
{
    public function run(): void
    {
        $dir = env('DEMO_MEDIA_DIR');
        if (app()->environment('production') || ! $dir || ! is_dir($dir)) {
            return;
        }
        $cfg = config('media.r2');
        $s3 = new S3Client(['version' => 'latest', 'region' => $cfg['region'] ?? 'auto', 'endpoint' => $cfg['endpoint'], 'use_path_style_endpoint' => true,
            'credentials' => ['key' => $cfg['key'], 'secret' => $cfg['secret']]]);
        if (! $s3->doesBucketExistV2($cfg['bucket'])) {
            $s3->createBucket(['Bucket' => $cfg['bucket']]);
        }
        $mm = User::query()->where('role', 'multimedia')->first();
        $comms = User::query()->where('role', 'communications')->first();
        $p = Project::query()->create(['name' => 'Creative Skills 2026', 'slug' => 'creative-skills-2026', 'status' => 'active', 'created_by' => $comms->id]);
        $events = collect(['Graduation ceremony', 'Studio open day', 'Community mural'])->map(fn ($t, $i) => Event::query()->create(['title' => $t, 'slug' => Str::slug($t), 'occurred_on' => now()->subWeeks(6 - $i * 2)->toDateString(), 'location' => 'Kigali', 'project_id' => $p->id]));
        $states = ['unreviewed', 'unreviewed', 'unreviewed', 'approved', 'approved', 'published', 'published', 'restricted', 'unreviewed'];
        $titles = ['Opening dance', 'Portrait session', 'Crowd at the gate', 'Certificates handed out', 'Painting the wall', 'Group photo', 'Behind the scenes', 'Workshop close-up', 'Evening performance'];
        $album = Album::query()->create(['title' => 'Graduation 2026', 'description' => 'Ceremony highlights', 'project_id' => $p->id, 'event_id' => $events[0]->id, 'created_by' => $comms->id]);
        foreach (glob(rtrim($dir, '/').'/demo??.webp') as $i => $file) {
            $thumb = str_replace('.webp', '_thumb.webp', $file);
            $uuid = (string) Str::uuid();
            [$w, $h] = getimagesize($file);
            $key = "originals/2026/10/{$uuid}/".basename($file);
            $s3->putObject(['Bucket' => $cfg['bucket'], 'Key' => $key, 'Body' => fopen($file, 'r'), 'ContentType' => 'image/webp']);
            $a = Asset::factory()->create([
                'uuid' => $uuid, 'title' => $titles[$i % 9].($i >= 9 ? ' II' : ''), 'original_filename' => basename($file), 'mime_type' => 'image/webp',
                'size_bytes' => random_int(3, 40) * 1024 * 1024, 'sha256' => hash_file('sha256', $file), 'storage_key' => $key, 'storage_status' => 'stored',
                'width' => $w, 'height' => $h, 'review_state' => $states[$i % 9], 'consent_status' => $i % 5 === 0 ? 'missing' : 'not_needed',
                'review_note' => $states[$i % 9] === 'restricted' ? 'Minor without guardian consent' : null,
                'uploaded_by' => $mm->id, 'project_id' => $p->id, 'event_id' => $events[$i % 3]->id, 'created_at' => now()->subDays(40 - $i * 2),
            ]);
            foreach (['thumb' => $thumb, 'preview' => $file] as $type => $f) {
                $dk = "derivatives/{$uuid}/{$type}.webp";
                $s3->putObject(['Bucket' => $cfg['bucket'], 'Key' => $dk, 'Body' => fopen($f, 'r'), 'ContentType' => 'image/webp']);
                [$dw, $dh] = getimagesize($f);
                AssetDerivative::query()->create(['asset_id' => $a->id, 'type' => $type, 'storage_key' => $dk, 'mime_type' => 'image/webp', 'width' => $dw, 'height' => $dh, 'size_bytes' => filesize($f), 'status' => 'ready']);
            }
            if ($i % 3 === 0) {
                $album->assets()->attach($a->id, ['position' => $i]);
            }
        }
    }
}
