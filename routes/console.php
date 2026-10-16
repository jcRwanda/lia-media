<?php

use App\Services\UploadService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('uploads:prune', function (UploadService $uploads) {
    $this->info($uploads->pruneExpired().' expired upload session(s) aborted.');
})->purpose('Abort expired upload sessions (frees incomplete multipart parts in R2)');

// No supervisor on the host: cron runs `schedule:run` every minute and the scheduler drains the database queue.
Schedule::command('queue:work database --stop-when-empty --max-time=50 --tries=3')->everyMinute()->withoutOverlapping(5)->runInBackground();
Schedule::command('uploads:prune')->hourly();

Artisan::command('storage:snapshot', function (\App\Services\StorageStatsService $stats) {
    $s = $stats->snapshot();
    $this->info("{$s['objects']} objects, ".number_format($s['bytes'] / 1024 ** 3, 2).' GB, est. $'.$s['estimated_monthly_usd'].'/month');
})->purpose('Count objects/bytes actually stored in the bucket');
Schedule::command('storage:snapshot')->dailyAt('02:30');
