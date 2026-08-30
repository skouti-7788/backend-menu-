<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
         'http://localhost:5174',
         'http://localhost:5173',
         'https://menu-online.vercel.app',
         'https://responsable-restaurant-sr5c.vercel.app'

    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
