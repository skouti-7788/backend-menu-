<?php

namespace Tests\Feature;

use App\Models\Meal;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicMenuOrderPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function createRestaurantWithTable(string $plan, string $tableStatus = 'available'): array
    {
        $owner = User::factory()->create(['role' => 'owner']);

        $restaurant = Restaurant::create([
            'user_id' => $owner->id,
            'name' => 'Test Restaurant',
            'plan' => $plan,
        ]);

        // Restaurant's creating hook intentionally assigns Starter.
        // Set the plan directly after creation for this entitlement test.
        $restaurant->forceFill(['plan' => $plan])->save();

        $table = RestaurantTable::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Table 1',
            'number' => 1,
            'status' => $tableStatus,
        ]);

        $meal = Meal::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Test Meal',
            'description' => 'Test description',
            'price' => 10,
            'status' => 'active',
        ]);

        return [$restaurant, $table, $meal];
    }

    protected function orderPayload(RestaurantTable $table, Meal $meal): array
    {
        return [
            'customer_name' => 'Guest Customer',
            'phone' => '0600000000',
            'address' => 'Table order',
            'table_token' => $table->qr_token,
            'items' => [
                ['meal_id' => $meal->id, 'quantity' => 1],
            ],
        ];
    }

    public function test_guest_can_place_order_for_pro_restaurant(): void
    {
        [$restaurant, $table, $meal] = $this->createRestaurantWithTable('pro');

        $response = $this->postJson(
            '/api/menu/'.$restaurant->slug.'/orders',
            $this->orderPayload($table, $meal)
        );

        $response->assertCreated()
            ->assertJsonPath('message', 'Order created successfully');

        $this->assertDatabaseHas('orders', [
            'restaurant_id' => $restaurant->id,
            'table_id' => $table->id,
        ]);
    }

    public function test_guest_can_place_order_for_business_restaurant(): void
    {
        [$restaurant, $table, $meal] = $this->createRestaurantWithTable('business');

        $response = $this->postJson(
            '/api/menu/'.$restaurant->slug.'/orders',
            $this->orderPayload($table, $meal)
        );

        $response->assertCreated();
    }

    public function test_guest_cannot_place_order_for_starter_restaurant(): void
    {
        [$restaurant, $table, $meal] = $this->createRestaurantWithTable('starter');

        $response = $this->postJson(
            '/api/menu/'.$restaurant->slug.'/orders',
            $this->orderPayload($table, $meal)
        );

        $response->assertForbidden()
            ->assertJsonPath('code', 'PLAN_UPGRADE_REQUIRED');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_guest_cannot_order_using_inactive_table_token(): void
    {
        [$restaurant, $table, $meal] = $this->createRestaurantWithTable('pro', 'inactive');

        $response = $this->postJson(
            '/api/menu/'.$restaurant->slug.'/orders',
            $this->orderPayload($table, $meal)
        );

        $response->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }
}
