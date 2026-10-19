<?php

use App\Services\DailyGalleryService;
use App\Services\DefaultAlbumService;
use App\Services\UploadService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('uploads:prune', function (UploadService $uploads) {
    $this->info($uploads->pruneExpired().' expired upload session(s) aborted.');
})->purpose('Abort expired upload sessions (frees incomplete multipart parts in R2)');

Artisan::command('gallery:backfill {--from=} {--to=}', function (DailyGalleryService $galleries, DefaultAlbumService $defaults) {
    $from = $this->option('from') ? CarbonImmutable::parse($this->option('from')) : null;
    $to = $this->option('to') ? CarbonImmutable::parse($this->option('to'))->endOfDay() : null;
    $defaults->ensure();
    $r = $galleries->backfill($from, $to);
    $this->info("Grouped {$r['assets']} asset(s): {$r['attached']} newly filed into {$r['galleries']} day gallery(ies).");
})->purpose('Group existing assets into per-day galleries (idempotent; safe to re-run)');

// No supervisor on the host: cron runs `schedule:run` every minute and the scheduler drains the database queue.
Schedule::command('queue:work database --stop-when-empty --max-time=50 --tries=3')->everyMinute()->withoutOverlapping(5)->runInBackground();
Schedule::command('uploads:prune')->hourly();

Artisan::command('storage:snapshot', function (\App\Services\StorageStatsService $stats) {
    $s = $stats->snapshot();
    $this->info("{$s['objects']} objects, ".number_format($s['bytes'] / 1024 ** 3, 2).' GB, est. $'.$s['estimated_monthly_usd'].'/month');
})->purpose('Count objects/bytes actually stored in the bucket');
Schedule::command('storage:snapshot')->dailyAt('02:30');
