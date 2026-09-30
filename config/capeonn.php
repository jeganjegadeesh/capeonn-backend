<?php

// Values used by database seeders. Read via config() (not env()) so it keeps
// working when the config is cached in production.
return [
    // Base URL of the Flutter web app; used to build links in emails (e.g. password reset).
    'frontend_url' => env('CAPEONN_FRONTEND_URL', 'http://localhost:8080'),

    'company' => [
        'name' => env('CAPEONN_COMPANY_NAME', 'Capeonn'),
        'code' => env('CAPEONN_COMPANY_CODE', 'CAP'),
    ],

    'admin' => [
        'name'     => env('CAPEONN_ADMIN_NAME', 'System Admin'),
        'email'    => env('CAPEONN_ADMIN_EMAIL', 'admin@capeonn.test'),
        // No default on purpose: outside local, the seeder refuses to run without one.
        'password' => env('CAPEONN_ADMIN_PASSWORD'),
    ],
];