<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Max upload size per context (bytes)
    |--------------------------------------------------------------------------
    */
    'max_sizes' => [
        'logo' => 1024 * 1024,           // 1 MB
        'contract' => 20 * 1024 * 1024,  // 20 MB
        'import' => 10 * 1024 * 1024,    // 10 MB
        'default' => 5 * 1024 * 1024,    // 5 MB
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed MIME types per context
    |--------------------------------------------------------------------------
    | Validated against BOTH the client-reported MIME and the actual file
    | contents (mime_content_type), guarding against extension/MIME spoofing.
    */
    'allowed_mimes' => [
        'logo' => [
            'image/jpeg',
            'image/png',
            'image/svg+xml',
            'image/webp',
        ],
        'contract' => [
            'application/pdf',
        ],
        'import' => [
            'text/csv',
            'text/plain',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Safe extensions per MIME
    |--------------------------------------------------------------------------
    | Filenames are generated; we never trust client-supplied extensions.
    */
    'extension_for_mime' => [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/svg+xml' => 'svg',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'text/csv' => 'csv',
        'text/plain' => 'csv',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ],

    /*
    |--------------------------------------------------------------------------
    | Signed URL TTL (minutes) — default for FileUploadService::getSignedUrl
    |--------------------------------------------------------------------------
    */
    'signed_url_minutes' => env('UPLOAD_SIGNED_URL_MINUTES', 30),

];
