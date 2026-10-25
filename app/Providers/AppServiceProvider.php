<?php

namespace App\Providers;

use App\Backup\BackupDriver;
use App\Backup\NullBackupDriver;
use App\Models\User;
use App\Storage\FakeObjectStorage;
use App\Storage\ObjectStorage;
use App\Storage\R2ObjectStorage;
use App\Support\Roles;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ObjectStorage::class, fn () => match (config('media.disk')) {
            'fake' => new FakeObjectStorage,
            default => new R2ObjectStorage(config('media.r2')),
        });
        $this->app->singleton(BackupDriver::class, fn () => match (config('media.backup_driver')) {
            default => new NullBackupDriver,
        });
    }

    public function boot(): void
    {
        foreach (array_keys(Roles::ABILITIES) as $ability) {
            Gate::define($ability, fn (User $user) => $user->canDo($ability));
        }

        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(5)->by(strtolower((string) $r->input('email')).'|'.$r->ip()),
            Limit::perMinute(20)->by($r->ip()),
        ]);
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(240)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('share', fn (Request $r) => Limit::perMinute(120)->by($r->ip()));
    }
}
