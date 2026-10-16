<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
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
