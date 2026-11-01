<?php

namespace Tests\Unit;

use App\Support\SubscriptionPlans;
use PHPUnit\Framework\TestCase;

class SubscriptionPlansTest extends TestCase
{
    public function test_unknown_or_null_plan_falls_back_to_starter(): void
    {
        self::assertSame('starter', SubscriptionPlans::for(null)['key']);
        self::assertSame('starter', SubscriptionPlans::for('invalid')['key']);
    }

    public function test_starter_limits_and_features_are_defined(): void
    {
        $starter = SubscriptionPlans::for('starter');

        self::assertSame(30, $starter['meals_limit']);
        self::assertSame(5, $starter['categories_limit']);
        self::assertTrue(SubscriptionPlans::allows('starter', 'digital_menu'));
        self::assertTrue(SubscriptionPlans::allows('starter', 'qr_code'));
        self::assertTrue(SubscriptionPlans::allows('starter', 'basic_appearance'));
        self::assertFalse(SubscriptionPlans::allows('starter', 'orders'));
        self::assertFalse(SubscriptionPlans::allows('starter', 'tables'));
        self::assertFalse(SubscriptionPlans::allows('starter', 'staff'));
    }

    public function test_pro_includes_orders_tables_and_advanced_appearance_but_not_staff(): void
    {
        self::assertNull(SubscriptionPlans::for('pro')['meals_limit']);
        self::assertNull(SubscriptionPlans::for('pro')['categories_limit']);
        self::assertTrue(SubscriptionPlans::allows('pro', 'orders'));
        self::assertTrue(SubscriptionPlans::allows('pro', 'tables'));
        self::assertTrue(SubscriptionPlans::allows('pro', 'advanced_appearance'));
        self::assertFalse(SubscriptionPlans::allows('pro', 'staff'));
    }

    public function test_business_includes_staff_and_pro_features(): void
    {
        self::assertTrue(SubscriptionPlans::allows('business', 'orders'));
        self::assertTrue(SubscriptionPlans::allows('business', 'tables'));
        self::assertTrue(SubscriptionPlans::allows('business', 'staff'));
        self::assertTrue(SubscriptionPlans::allows('business', 'advanced_appearance'));
    }
}
