<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AlbumController;
use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\TaxonomyController;
use App\Http\Controllers\Api\DerivativeController;
use App\Http\Controllers\Api\LookupController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::get('/lookups', LookupController::class)->middleware('can:library.view');

    Route::middleware('can:users.manage')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::patch('/users/{user}', [UserController::class, 'update']);
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
