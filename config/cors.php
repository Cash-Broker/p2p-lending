<?php

return [

    // Only API and Sanctum cookie endpoints need CORS — everything else is same-origin
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    // Explicit whitelist — never '*' in production.
    // APP_URL ensures only our own frontend can make cross-origin requests.
    'allowed_origins' => [env('APP_URL', 'https://p2p-lending.test')],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'X-Requested-With', 'Authorization', 'Accept', 'X-XSRF-TOKEN'],

    'exposed_headers' => [],

    'max_age' => 7200, // Preflight cache 2 hours — reduces OPTIONS requests

    // Required for Sanctum cookie auth — browser must send cookies cross-origin
    'supports_credentials' => true,

];
