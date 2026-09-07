<?php

return [
    'mode' => 'server',
    'storage_limit_bytes' => (int) env('SENDAE_STORAGE_LIMIT_MB', 1024) * 1024 * 1024,
    'draft_limit' => (int) env('SENDAE_DRAFT_LIMIT', 500),
    'providers' => [
        'x' => ['label' => 'X', 'client_id' => env('X_CLIENT_ID'), 'client_secret' => env('X_CLIENT_SECRET')],
        'threads' => ['label' => 'Threads', 'client_id' => env('THREADS_CLIENT_ID'), 'client_secret' => env('THREADS_CLIENT_SECRET')],
        'facebook' => ['label' => 'Facebook Page', 'client_id' => env('FACEBOOK_CLIENT_ID'), 'client_secret' => env('FACEBOOK_CLIENT_SECRET')],
        'linkedin' => ['label' => 'LinkedIn profile', 'client_id' => env('LINKEDIN_CLIENT_ID'), 'client_secret' => env('LINKEDIN_CLIENT_SECRET')],
        'linkedin_page' => ['label' => 'LinkedIn Company Page', 'client_id' => env('LINKEDIN_CLIENT_ID'), 'client_secret' => env('LINKEDIN_CLIENT_SECRET'), 'approved' => env('LINKEDIN_PAGES_APPROVED', false)],
    ],
    'meta_version' => env('META_GRAPH_VERSION', 'v24.0'),
    'linkedin_personal_analytics' => env('LINKEDIN_PERSONAL_ANALYTICS', false),
    'linkedin_version' => env('LINKEDIN_VERSION', '202608'),
];
