<?php

namespace Tests\Feature;

use App\Models\Meal;
use App\Models\MenuCategory;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanLimitEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function createRestaurantWithPlan(string $plan = 'starter'): array
    {
        $owner = User::create([
            'name' => 'Limit Test Owner',
            'email' => 'limit-owner@example.com',
            'password' => bcrypt('password123'),
            'role' => 'owner',
        ]);

        $restaurant = Restaurant::create([
            'user_id' => $owner->id,
            'name' => 'Limit Test Restaurant',
            'slug' => 'limit-test-restaurant',
        ]);

        if ($plan !== 'starter') {
            $restaurant->subscriptions()->create([
                'plan' => $plan,
                'billing_interval' => 'monthly',
                'status' => 'active',
                'amount' => $plan === 'business' ? 199 : 99,
                'currency' => 'MAD',
                'starts_at' => now()->subMinute(),
            ]);
        }

        return [$owner, $restaurant];
    }

    private function createCategory(Restaurant $restaurant, int $index): MenuCategory
    {
        return MenuCategory::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Category '.$index,
            'description' => 'Test category '.$index,
            'status' => 'active',
        ]);
    }

    private function createMeal(Restaurant $restaurant, MenuCategory $category, int $index): Meal
    {
        return Meal::create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'name' => 'Meal '.$index,
            'description' => 'Test meal '.$index,
            'price' => 25,
            'status' => 'active',
            'featured' => false,
        ]);
    }

    public function test_starter_allows_fifth_category_and_rejects_sixth(): void
    {
        [$owner, $restaurant] = $this->createRestaurantWithPlan();

        for ($i = 1; $i <= 4; $i++) {
            $this->createCategory($restaurant, $i);
        }

        $payload = [
            'name' => 'Category 5',
            'description' => 'The fifth category',
            'status' => 'active',
        ];

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/restaurants/{$restaurant->id}/categories", $payload)
            ->assertCreated();

        $this->postJson("/api/restaurants/{$restaurant->id}/categories", [
            ...$payload,
            'name' => 'Category 6',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'PLAN_LIMIT_REACHED')
            ->assertJsonPath('resource', 'categories')
            ->assertJsonPath('limit', 5)
            ->assertJsonPath('current', 5)
            ->assertJsonPath('upgrade_required', true);
    }

    public function test_starter_allows_thirtieth_meal_and_rejects_thirty_first(): void
    {
        [$owner, $restaurant] = $this->createRestaurantWithPlan();
        $category = $this->createCategory($restaurant, 1);

        for ($i = 1; $i <= 29; $i++) {
            $this->createMeal($restaurant, $category, $i);
        }

        $payload = [
            'category_id' => $category->id,
            'name' => 'Meal 30',
            'description' => 'The thirtieth meal',
            'price' => 25,
            'status' => 'active',
        ];

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/restaurants/{$restaurant->id}/meals", $payload)
            ->assertCreated();

        $this->postJson("/api/restaurants/{$restaurant->id}/meals", [
            ...$payload,
            'name' => 'Meal 31',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'PLAN_LIMIT_REACHED')
            ->assertJsonPath('resource', 'meals')
            ->assertJsonPath('limit', 30)
            ->assertJsonPath('current', 30)
            ->assertJsonPath('upgrade_required', true);
    }

    public function test_pro_plan_has_no_category_or_meal_creation_limits(): void
    {
        [$owner, $restaurant] = $this->createRestaurantWithPlan('pro');

        for ($i = 1; $i <= 6; $i++) {
            $this->createCategory($restaurant, $i);
        }

        $category = $restaurant->categories()->firstOrFail();

        for ($i = 1; $i <= 31; $i++) {
            $this->createMeal($restaurant, $category, $i);
        }

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/restaurants/{$restaurant->id}/categories", [
                'name' => 'Category 7',
                'description' => 'Pro category',
                'status' => 'active',
            ])
            ->assertCreated();

        $this->postJson("/api/restaurants/{$restaurant->id}/meals", [
            'category_id' => $category->id,
            'name' => 'Meal 32',
            'description' => 'Pro meal',
            'price' => 30,
            'status' => 'active',
        ])->assertCreated();
    }

    public function test_business_plan_has_no_category_or_meal_creation_limits(): void
    {
        [$owner, $restaurant] = $this->createRestaurantWithPlan('business');

        for ($i = 1; $i <= 6; $i++) {
            $this->createCategory($restaurant, $i);
        }

        $category = $restaurant->categories()->firstOrFail();

        for ($i = 1; $i <= 31; $i++) {
            $this->createMeal($restaurant, $category, $i);
        }

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/restaurants/{$restaurant->id}/categories", [
                'name' => 'Business category',
                'description' => 'Business category',
                'status' => 'active',
            ])
            ->assertCreated();

        $this->postJson("/api/restaurants/{$restaurant->id}/meals", [
            'category_id' => $category->id,
            'name' => 'Business meal',
            'description' => 'Business meal',
            'price' => 35,
            'status' => 'active',
        ])->assertCreated();
    }
}
