<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'cloudflare' => [
        'token' => env('CLOUDFLARE_API_TOKEN'),
    ],

    'postmark_token' => env('POSTMARK_TOKEN'),

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
    ],

    'quickbooks' => [
        'client_id' => env('QBO_CLIENT_ID'),
    ],

    /*
     * Public-facing URLs for the consumer products. Read by the
     * portal dashboard launcher to build SSO redirect targets and
     * by ProductLaunchController to POST token-exchange requests.
     * Defaults mirror production; staging overrides via .env.
     */
    'products' => [
        'maavelus_url' => env('MAAVELUS_APP_URL', 'https://restaurant.maavelus.co.uk'),
        // HMAC-SHA256 shared secret for outbound webhooks to Maavelus.
        'maavelus_secret' => env('MAAVELUS_WEBHOOK_SECRET'),
        'myorderpad_url' => env('MYORDERPAD_APP_URL', 'https://app.myorderpad.co.uk'),
        'myorderpad_secret' => env('MYORDERPAD_WEBHOOK_SECRET'),
    ],

    /*
     * OAuth client IDs for the products that consume Powerhouse SSO.
     * The IDs are UUIDs (Passport v12+ schema). Defaults match the
     * seed data already in oauth_clients on local; production reads
     * the actual UUIDs from .env. Secrets are server-side only —
     * the consumer apps store them in their own configs.
     */
    'oauth_clients' => [
        'maavelus_id' => env('MAAVELUS_OAUTH_ID', '019e6f1a-c6b2-738b-a483-a7a51cb22742'),
        'maavelus_secret' => env('MAAVELUS_OAUTH_SECRET'),
        'maavelus_redirect' => env('MAAVELUS_OAUTH_REDIRECT', 'https://restaurant.maavelus.co.uk/oauth/callback'),

        'myorderpad_id' => env('MYORDERPAD_OAUTH_ID', '019e6f1a-c841-7275-83b5-16f8b9697033'),
        'myorderpad_secret' => env('MYORDERPAD_OAUTH_SECRET'),
        'myorderpad_redirect' => env('MYORDERPAD_OAUTH_REDIRECT', 'https://myorderpad.com/oauth/callback'),

        'whitedash_portal_id' => env('WHITEDASH_PORTAL_OAUTH_ID', '019e6f1a-c524-718f-83b5-001ad023f194'),
        'whitedash_portal_secret' => env('WHITEDASH_PORTAL_OAUTH_SECRET'),
        'whitedash_portal_redirect' => env('WHITEDASH_PORTAL_OAUTH_REDIRECT'),
    ],

];
