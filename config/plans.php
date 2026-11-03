<?php

return [
    'starter' => [
        'name' => 'Starter',
        'price_monthly' => 0,
        'price_yearly' => 0,
        'limits' => ['meals' => 30, 'categories' => 5],
        'features' => [
            'menu_qr' => true,
            'orders' => false,
            'tables' => false,
            'staff' => false,
            'appearance' => 'basic',
        ],
    ],
    'pro' => [
        'name' => 'Pro',
        'price_monthly' => 99,
        'price_yearly' => 990,
        'limits' => ['meals' => null, 'categories' => null],
        'features' => [
            'menu_qr' => true,
            'orders' => true,
            'tables' => true,
            'staff' => false,
            'appearance' => 'advanced',
        ],
    ],
    'business' => [
        'name' => 'Business',
        'price_monthly' => 199,
        'price_yearly' => 1990,
        'limits' => ['meals' => null, 'categories' => null],
        'features' => [
            'menu_qr' => true,
            'orders' => true,
            'tables' => true,
            'staff' => true,
            'appearance' => 'advanced',
        ],
    ],
];
