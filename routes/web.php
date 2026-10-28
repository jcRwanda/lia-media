<?php

use Illuminate\Support\Facades\Route;

// Embeddable hosted page
Route::get('/request-materials', function () {
    $type = request()->query('type', 'client');
    if (! in_array($type, ['alumni', 'trainee', 'client'], true)) {
        $type = 'client';
    }
    $allowed = config('material-requests.allowed_origins', []);
    $frameAncestors = "'self' ".implode(' ', array_map('trim', $allowed));
    $response = response()->view('request-materials', [
        'type' => $type,
        'bilingualTerms' => config('material-requests.bilingual_terms'),
        'consentDeclaration' => config('material-requests.consent_declaration'),
        'fieldsConfig' => config('material-requests.fields'),
        'labels' => config('material-requests.labels'),
        'requesterTypes' => config('material-requests.requester_types'),
    ]);
    $response->headers->set('Content-Security-Policy', 'frame-ancestors '.$frameAncestors);

    return $response;
})->middleware('material.cors');

// The React SPA handles every non-API path (same origin, Sanctum cookie auth).
Route::view('/request-status/{uuid}', 'request-status', [])->whereUuid('uuid')->name('request-status');
Route::view('/{any?}', 'app')->where('any', '^(?!(api|sanctum|up|request-materials|request-status)(/|$)).*$')->name('spa');
