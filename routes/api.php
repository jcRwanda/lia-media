<?php

use App\Http\Controllers\Api\AlbumController;
use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConsentController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DerivativeController;
use App\Http\Controllers\Api\LookupController;
use App\Http\Controllers\Api\PublicationController;
use App\Http\Controllers\Api\PublicShareController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\ShareLinkController;
use App\Http\Controllers\Api\TaxonomyController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

// Visitor share endpoints (no staff session).
Route::middleware('throttle:share')->prefix('s/{token}')->where(['token' => '[A-Za-z0-9_-]{20,64}'])->group(function () {
    Route::get('/', [PublicShareController::class, 'show']);
    Route::post('/unlock', [PublicShareController::class, 'unlock']);
    Route::post('/items/{asset}/download', [PublicShareController::class, 'download']);
    Route::get('/items/{asset}/file', [PublicShareController::class, 'file']);
    Route::post('/download', [PublicShareController::class, 'downloadZip']);
});

Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {
    // Available before the temporary password has been replaced.
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/change-password', [AuthController::class, 'changePassword']);

    // Everything else stays locked until must_change_password is cleared.
    Route::middleware('must_change')->group(function () {
        Route::get('/lookups', LookupController::class)->middleware('can:library.view');

        Route::middleware('can:users.manage')->group(function () {
            Route::get('/users', [UserController::class, 'index']);
            Route::post('/users', [UserController::class, 'store']);
            Route::patch('/users/{user}', [UserController::class, 'update']);
            Route::post('/users/{user}/resend-welcome', [UserController::class, 'resendWelcome'])->whereNumber('user');
        });
        Route::middleware('can:library.view')->group(function () {
            Route::get('/assets', [AssetController::class, 'index']);
            Route::get('/assets/{asset}', [AssetController::class, 'show']);
            Route::get('/albums', [AlbumController::class, 'index']);
            Route::get('/albums/{album}', [AlbumController::class, 'show']);
            Route::get('/taxonomy/{type}', [TaxonomyController::class, 'index'])->whereIn('type', ['projects', 'cohorts', 'events', 'categories']);
        });
        Route::post('/assets/{asset}/download', [AssetController::class, 'download'])->middleware('can:assets.download_original');
        Route::middleware('can:assets.edit')->group(function () {
            Route::patch('/assets/{asset}', [AssetController::class, 'update']);
            Route::post('/assets-bulk', [AssetController::class, 'bulk']);
        });
        Route::delete('/assets/{asset}', [AssetController::class, 'destroy'])->middleware('can:assets.delete');
        Route::middleware('can:albums.manage')->group(function () {
            Route::post('/albums', [AlbumController::class, 'store']);
            Route::patch('/albums/{album}', [AlbumController::class, 'update']);
            Route::delete('/albums/{album}', [AlbumController::class, 'destroy']);
            Route::post('/albums/{album}/assets', [AlbumController::class, 'addAssets']);
            Route::delete('/albums/{album}/assets/{asset}', [AlbumController::class, 'removeAsset']);
            Route::put('/albums/{album}/order', [AlbumController::class, 'reorder']);
        });
        Route::middleware('can:dashboard.view')->group(function () {
            Route::get('/dashboard', [DashboardController::class, 'index']);
            Route::get('/storage', [DashboardController::class, 'storage']);
        });
        Route::post('/storage/snapshot', [DashboardController::class, 'snapshot'])->middleware('can:settings.manage');
        Route::middleware('can:settings.manage')->group(function () {
            Route::get('/settings', [SettingsController::class, 'show']);
            Route::patch('/settings', [SettingsController::class, 'update']);
        });
        // Each transition checks review.approve / review.publish itself.
        Route::middleware('can:review.approve')->group(function () {
            Route::post('/assets/{asset}/review', [ReviewController::class, 'update']);
            Route::post('/review/bulk', [ReviewController::class, 'bulk']);
        });
        Route::middleware('can:library.view')->group(function () {
            Route::get('/people', [ConsentController::class, 'people']);
            Route::get('/publications', [PublicationController::class, 'index']);
        });
        Route::middleware('can:consent.manage')->group(function () {
            Route::post('/people', [ConsentController::class, 'storePerson']);
            Route::post('/people/{person}/consents', [ConsentController::class, 'storeConsent']);
            Route::post('/consents/{consent}/withdraw', [ConsentController::class, 'withdraw']);
            Route::post('/assets/{asset}/people', [ConsentController::class, 'linkPerson']);
            Route::delete('/assets/{asset}/people/{person}', [ConsentController::class, 'unlinkPerson']);
            Route::post('/assets/{asset}/consent-status', [ConsentController::class, 'setStatus']);
        });
        Route::middleware('can:publications.manage')->group(function () {
            Route::post('/assets/{asset}/publications', [PublicationController::class, 'store']);
            Route::delete('/publications/{publication}', [PublicationController::class, 'destroy']);
        });
        Route::middleware('can:shares.manage')->group(function () {
            Route::get('/shares', [ShareLinkController::class, 'index']);
            Route::post('/shares', [ShareLinkController::class, 'store']);
            Route::get('/shares/{share}', [ShareLinkController::class, 'show']);
            Route::post('/shares/{share}/revoke', [ShareLinkController::class, 'revoke']);
            Route::post('/shares/{share}/rotate', [ShareLinkController::class, 'rotate']);
            Route::post('/shares/{share}/recipients', [ShareLinkController::class, 'addRecipient']);
            Route::delete('/shares/{share}/recipients/{recipient}', [ShareLinkController::class, 'revokeRecipient'])->whereNumber('recipient');
        });
        Route::middleware('can:taxonomy.manage')->whereIn('type', ['projects', 'cohorts', 'events', 'categories'])->group(function () {
            Route::post('/taxonomy/{type}', [TaxonomyController::class, 'store']);
            Route::patch('/taxonomy/{type}/{id}', [TaxonomyController::class, 'update'])->whereNumber('id');
            Route::delete('/taxonomy/{type}/{id}', [TaxonomyController::class, 'destroy'])->whereNumber('id');
        });
        Route::middleware('can:assets.upload')->group(function () {
            Route::get('/uploads', [UploadController::class, 'index']);
            Route::post('/uploads', [UploadController::class, 'store']);
            Route::get('/uploads/{upload}', [UploadController::class, 'show']);
            Route::post('/uploads/{upload}/parts', [UploadController::class, 'parts']);
            Route::post('/uploads/{upload}/renew', [UploadController::class, 'renew']);
            Route::post('/uploads/{upload}/complete', [UploadController::class, 'complete']);
            Route::delete('/uploads/{upload}', [UploadController::class, 'destroy']);
            Route::post('/assets/{asset}/derivatives', [DerivativeController::class, 'store']);
            Route::post('/assets/{asset}/derivatives/{type}/complete', [DerivativeController::class, 'complete']);
        });
        Route::get('/audit-logs', [AuditLogController::class, 'index'])->middleware('can:audit.view');
    });
});
