<?php

return [
    // Object storage adapter: r2 (S3 API) or fake (tests / offline UI work).
    'disk' => env('MEDIA_DISK', 'r2'),

    'r2' => [
        'account_id' => env('R2_ACCOUNT_ID'),
        'key' => env('R2_ACCESS_KEY_ID'),
        'secret' => env('R2_SECRET_ACCESS_KEY'),
        'bucket' => env('R2_BUCKET', 'lia-media-archive'),
        'endpoint' => env('R2_ENDPOINT'),
        'region' => env('R2_REGION', 'auto'),
        'use_path_style' => (bool) env('R2_USE_PATH_STYLE', true),
    ],

    'ttl' => [
        'upload' => (int) env('MEDIA_UPLOAD_URL_TTL', 3600),
        'preview' => (int) env('MEDIA_PREVIEW_URL_TTL', 300),
        'download' => (int) env('MEDIA_DOWNLOAD_URL_TTL', 600),
    ],

    'multipart_threshold' => (int) env('MEDIA_MULTIPART_THRESHOLD', 100 * 1024 * 1024),
    'part_size' => (int) env('MEDIA_PART_SIZE', 32 * 1024 * 1024),
    'max_file_size' => (int) env('MEDIA_MAX_FILE_SIZE', 20 * 1024 * 1024 * 1024),
    'upload_session_days' => 7,

    // Allowed upload types (prefix match on "type/").
    'allowed_mime_prefixes' => ['image/', 'video/', 'audio/'],
    'allowed_mimes' => [
        'application/pdf', 'application/zip', 'text/plain',
        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ],
    'blocked_mimes' => ['image/svg+xml'], // script-capable; store as zip if really needed

    'derivatives' => [
        'thumb' => ['max' => 480, 'mimes' => ['image/webp', 'image/jpeg']],
        'preview' => ['max' => 1920, 'mimes' => ['image/webp', 'image/jpeg']],
        'poster' => ['max' => 1920, 'mimes' => ['image/webp', 'image/jpeg']],
    ],
    'derivative_max_bytes' => 8 * 1024 * 1024,

    'backup_driver' => env('MEDIA_BACKUP_DRIVER', 'null'),
];
