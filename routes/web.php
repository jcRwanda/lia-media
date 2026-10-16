<?php

use Illuminate\Support\Facades\Route;

// The React SPA handles every non-API path (same origin, Sanctum cookie auth).
Route::view('/{any?}', 'app')->where('any', '^(?!(api|sanctum|up)(/|$)).*$')->name('spa');
