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
        'receipt' => 5 * 1024 * 1024,    // 5 MB — PDF or photo
        'task_attachment' => 10 * 1024 * 1024, // 10 MB — docs, sheets, images, archives
        'project_file' => 10 * 1024 * 1024,    // 10 MB — project files (overridable per upload via Setting)
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
        // Receipts are uploaded by staff for expense audit trails;
        // PDF for vendor invoices, photos for hand-written receipts.
        'receipt' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
        ],
        'import' => [
            'text/csv',
            'text/plain',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ],
        // Task attachments accept the common office/doc/image/archive set.
        // OOXML files (docx/xlsx/pptx) frequently sniff as application/zip
        // via mime_content_type (they ARE zip containers), so the zip MIMEs
        // are allow-listed too — without them a legitimate .docx is rejected
        // because the real-bytes check disagrees with the client MIME.
        'task_attachment' => [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/zip',
            'application/x-zip-compressed',
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'text/plain',
            'text/csv',
        ],
        // Project files accept the same set as task attachments. zip MIMEs
        // are allow-listed because OOXML files sniff as application/zip.
        'project_file' => [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/zip',
            'application/x-zip-compressed',
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'text/plain',
            'text/csv',
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
        'image/gif' => 'gif',
        'application/pdf' => 'pdf',
        'text/csv' => 'csv',
        'text/plain' => 'csv',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/zip' => 'zip',
        'application/x-zip-compressed' => 'zip',
    ],

    /*
    |--------------------------------------------------------------------------
    | Signed URL TTL (minutes) — default for FileUploadService::getSignedUrl
    |--------------------------------------------------------------------------
    */
    'signed_url_minutes' => env('UPLOAD_SIGNED_URL_MINUTES', 30),

];
