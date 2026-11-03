<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
         'http://localhost:5174',
         'http://localhost:5173',
         'https://menu-online.vercel.app',
         'https://menuonlinesr02.vercel.app',
         'https://menuonlinesr26.vercel.app',

    ],

    'allowed_origins_patterns' => [
        // Vercel preview deployments for the public Menu Online frontend.
        '#^https://menuonlinesr02-[a-z0-9-]+-skouti-7788s-projects\.vercel\.app$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
