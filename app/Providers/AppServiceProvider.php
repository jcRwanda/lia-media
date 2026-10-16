<?php

namespace App\Providers;

use App\Models\User;
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
        $this->app->singleton(\App\Storage\ObjectStorage::class, fn () => match (config('media.disk')) {
            'fake' => new \App\Storage\FakeObjectStorage,
            default => new \App\Storage\R2ObjectStorage(config('media.r2')),
        });
        $this->app->singleton(\App\Backup\BackupDriver::class, fn () => match (config('media.backup_driver')) {
            default => new \App\Backup\NullBackupDriver,
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
