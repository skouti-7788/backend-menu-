<?php

namespace App\Support;

final class SubscriptionPlans
{
    public const DEFINITIONS = [
        'starter' => [
            'name' => 'Starter',
            'meals_limit' => 30,
            'categories_limit' => 5,
            'features' => ['digital_menu', 'qr_code', 'basic_appearance', 'profile', 'dashboard'],
        ],
        'pro' => [
            'name' => 'Pro',
            'meals_limit' => null,
            'categories_limit' => null,
            'features' => ['digital_menu', 'qr_code', 'orders', 'tables', 'advanced_appearance', 'profile', 'dashboard'],
        ],
        'business' => [
            'name' => 'Business',
            'meals_limit' => null,
            'categories_limit' => null,
            'features' => ['digital_menu', 'qr_code', 'orders', 'tables', 'staff', 'advanced_appearance', 'profile', 'dashboard'],
        ],
    ];

    public static function for(string|null $plan): array
    {
        $key = array_key_exists($plan ?? '', self::DEFINITIONS) ? $plan : 'starter';

        return ['key' => $key] + self::DEFINITIONS[$key];
    }

    public static function allows(string|null $plan, string $feature): bool
    {
        return in_array($feature, self::for($plan)['features'], true);
    }
}
