<?php

namespace Tests\Feature;

use App\Models\MenuCategory;
use App\Models\Meal;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicMenuSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_menu_only_returns_tables_for_the_requested_restaurant(): void
    {
        $owner = User::create([
            'name' => 'Owner One',
            'email' => 'owner1@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'restaurant_manager',
        ]);

        $restaurantA = Restaurant::create([
            'user_id' => $owner->id,
            'name' => 'Alpha Bistro',
            'slug' => 'alpha-bistro',
        ]);

        $restaurantB = Restaurant::create([
            'user_id' => $owner->id,
            'name' => 'Beta Bistro',
            'slug' => 'beta-bistro',
        ]);

        $restaurantATable = RestaurantTable::create([
            'restaurant_id' => $restaurantA->id,
            'name' => 'Table 1',
            'number' => 1,
            'status' => 'available',
        ]);

        RestaurantTable::create([
            'restaurant_id' => $restaurantB->id,
            'name' => 'Table 7',
            'number' => 7,
            'status' => 'available',
        ]);

        $response = $this->getJson('/api/menu/' . $restaurantA->slug);

        $response->assertOk();
        $response->assertJsonPath('restaurant.slug', $restaurantA->slug);
        $this->assertCount(1, $response->json('tables'));
        $this->assertSame($restaurantATable->qr_token, $response->json('tables.0.qr_token'));
    }

    public function test_public_order_rejects_cross_restaurant_meals_and_forces_pending_status(): void
    {
        $owner = User::create([
            'name' => 'Owner Two',
            'email' => 'owner2@example.com',
            'password' => bcrypt('secret456'),
            'role' => 'restaurant_manager',
        ]);

        $restaurantA = Restaurant::create([
            'user_id' => $owner->id,
            'name' => 'Alpha Kitchen',
            'slug' => 'alpha-kitchen',
        ]);

        $restaurantB = Restaurant::create([
            'user_id' => $owner->id,
            'name' => 'Beta Kitchen',
            'slug' => 'beta-kitchen',
        ]);

        $categoryA = MenuCategory::create([
            'restaurant_id' => $restaurantA->id,
            'name' => 'Starters',
            'status' => 'active',
        ]);

        $categoryB = MenuCategory::create([
            'restaurant_id' => $restaurantB->id,
            'name' => 'Desserts',
            'status' => 'active',
        ]);

        $table = RestaurantTable::create([
            'restaurant_id' => $restaurantA->id,
            'name' => 'Window',
            'number' => 2,
            'qr_token' => 'alpha-window',
            'status' => 'available',
        ]);

        $mealA = Meal::create([
            'restaurant_id' => $restaurantA->id,
            'category_id' => $categoryA->id,
            'name' => 'Burger',
            'description' => 'Large burger',
            'price' => 18.50,
            'status' => 'active',
            'featured' => true,
        ]);

        $mealB = Meal::create([
            'restaurant_id' => $restaurantB->id,
            'category_id' => $categoryB->id,
            'name' => 'Cake',
            'description' => 'Chocolate cake',
            'price' => 12.00,
            'status' => 'active',
            'featured' => false,
        ]);

        $response = $this->postJson('/api/menu/' . $restaurantA->slug . '/orders', [
            'customer_name' => 'Alice',
            'phone' => '0600000000',
            'address' => 'Table 2',
            'status' => 'completed',
            'table_token' => $table->qr_token,
            'items' => [
                ['meal_id' => $mealB->id, 'quantity' => 2],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('orders', ['restaurant_id' => $restaurantA->id]);
        $this->assertDatabaseMissing('order_items', ['meal_id' => $mealB->id]);
    }
}
