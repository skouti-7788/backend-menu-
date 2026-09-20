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
        $response->assertJsonPath('tables.0.id', $restaurantATable->id);
        $response->assertJsonPath('tables.0.number', 1);
        $response->assertJsonMissingPath('tables.0.qr_token');
    }

    public function test_public_menu_hides_table_tokens_and_only_exposes_safe_table_fields(): void
    {
        $owner = User::create([
            'name' => 'Owner Three',
            'email' => 'owner3@example.com',
            'password' => bcrypt('secret789'),
            'role' => 'restaurant_manager',
        ]);

        $restaurant = Restaurant::create([
            'user_id' => $owner->id,
            'name' => 'Gamma Bistro',
            'slug' => 'gamma-bistro',
        ]);

        $table = RestaurantTable::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Table 9',
            'number' => 9,
            'status' => 'available',
        ]);

        $response = $this->getJson('/api/menu/' . $restaurant->slug);

        $response->assertOk();
        $response->assertJsonPath('tables.0.id', $table->id);
        $response->assertJsonPath('tables.0.number', 9);
        $response->assertJsonMissingPath('tables.0.qr_token');
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

    public function test_public_order_rejects_inactive_meals(): void
    {
        $owner = User::create([
            'name' => 'Owner Four',
            'email' => 'owner4@example.com',
            'password' => bcrypt('secretabc'),
            'role' => 'restaurant_manager',
        ]);

        $restaurant = Restaurant::create([
            'user_id' => $owner->id,
            'name' => 'Delta Kitchen',
            'slug' => 'delta-kitchen',
        ]);

        $category = MenuCategory::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Lunch',
            'status' => 'active',
        ]);

        $table = RestaurantTable::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Table 1',
            'number' => 1,
            'status' => 'available',
        ]);

        $meal = Meal::create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'name' => 'Soup',
            'description' => 'Disabled item',
            'price' => 9.50,
            'status' => 'inactive',
            'featured' => false,
        ]);

        $response = $this->postJson('/api/menu/' . $restaurant->slug . '/orders', [
            'customer_name' => 'Bob',
            'phone' => '0612345678',
            'address' => 'Table 1',
            'table_token' => $table->qr_token,
            'items' => [
                ['meal_id' => $meal->id, 'quantity' => 1],
            ],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('orders', ['restaurant_id' => $restaurant->id]);
    }
    public function test_public_order_calculates_total_and_reserves_table(): void
    {
        $owner = User::create([
            'name' => 'Public Order Owner',
            'email' => 'public-order-owner@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'restaurant_manager',
        ]);

        $restaurant = Restaurant::create([
            'user_id' => $owner->id,
            'name' => 'Public Order Bistro',
            'slug' => 'public-order-bistro',
        ]);

        $category = MenuCategory::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Main',
            'status' => 'active',
        ]);

        $table = RestaurantTable::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Table 1',
            'number' => 1,
            'status' => 'available',
        ]);

        $meal = Meal::create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'name' => 'Burger',
            'description' => 'Fresh burger',
            'price' => 18.50,
            'status' => 'active',
            'featured' => false,
        ]);

        $response = $this->postJson(
            '/api/menu/' . $restaurant->slug . '/orders',
            [
                'customer_name' => 'Public Customer',
                'phone' => '0600000000',
                'address' => 'Table 1',
                'table_token' => $table->qr_token,
                // 'status' => 'completed',
                'items' => [
                    [
                        'meal_id' => $meal->id,
                        'quantity' => 2,
                    ],
                ],
            ]
        );

        $response->assertStatus(201);

        // 18.50 × 2 = 37.00
        // 9% tax = 3.33
        // Total = 40.33
        $this->assertDatabaseHas('orders', [
            'restaurant_id' => $restaurant->id,
            'table_id' => $table->id,
            'status' => 'pending',
            'total' => 40.33,
        ]);

        $this->assertDatabaseHas('order_items', [
            'meal_id' => $meal->id,
            'quantity' => 2,
            'unit_price' => 18.50,
            'total_price' => 37.00,
        ]);

        $this->assertDatabaseHas('restaurant_tables', [
            'id' => $table->id,
            'status' => 'reserved',
            
        ]);
    }
    public function test_public_menu_rejects_invalid_language(): void
    {
        $owner = User::create([
            'name' => 'Language Owner',
            'email' => 'language-owner@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'restaurant_manager',
        ]);

        $restaurant = Restaurant::create([
            'user_id' => $owner->id,
            'name' => 'Language Bistro',
            'slug' => 'language-bistro',
        ]);

        $response = $this->getJson(
            '/api/menu/' . $restaurant->slug . '?lang=de'
        );

        $response->assertStatus(422);
    }

    
    public function test_record_view_rejects_invalid_language(): void
    {
        $owner = User::create([
            'name' => 'View Owner',
            'email' => 'view-owner@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'restaurant_manager',
        ]);

        $restaurant = Restaurant::create([
            'user_id' => $owner->id,
            'name' => 'View Bistro',
            'slug' => 'view-bistro',
        ]);

        $response = $this->postJson(
            '/api/menu/' . $restaurant->slug . '/view?lang=de'
        );

        $response->assertStatus(422);

        $this->assertDatabaseMissing('menu_views', [
            'restaurant_id' => $restaurant->id,
        ]);
    }


    public function test_record_view_accepts_valid_language_without_meal(): void
    {
        $owner = User::create([
            'name' => 'Analytics Owner',
            'email' => 'analytics-owner@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'restaurant_manager',
        ]);

        $restaurant = Restaurant::create([
            'user_id' => $owner->id,
            'name' => 'Analytics Bistro',
            'slug' => 'analytics-bistro',
        ]);

        $response = $this->postJson(
            '/api/menu/' . $restaurant->slug . '/view?lang=fr'
        );

        $response->assertOk();

        $this->assertDatabaseHas('menu_views', [
            'restaurant_id' => $restaurant->id,
            'meal_id' => null,
            'language' => 'fr',
        ]);
    }


    public function test_record_view_accepts_active_meal_from_same_restaurant(): void
    {
        $owner = User::create([
            'name' => 'Meal View Owner',
            'email' => 'meal-view-owner@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'restaurant_manager',
        ]);

        $restaurant = Restaurant::create([
            'user_id' => $owner->id,
            'name' => 'Meal View Bistro',
            'slug' => 'meal-view-bistro',
        ]);

        $category = MenuCategory::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Main',
            'status' => 'active',
        ]);

        $meal = Meal::create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'name' => 'Burger',
            'description' => 'Fresh burger',
            'price' => 15.00,
            'status' => 'active',
            'featured' => false,
        ]);

        $response = $this->postJson(
            '/api/menu/' . $restaurant->slug . '/view?lang=en',
            [
                'meal_id' => $meal->id,
            ]
        );

        $response->assertOk();

        $this->assertDatabaseHas('menu_views', [
            'restaurant_id' => $restaurant->id,
            'meal_id' => $meal->id,
            'language' => 'en',
        ]);
    }


    public function test_record_view_rejects_meal_from_another_restaurant(): void
    {
        $owner = User::create([
            'name' => 'Isolation Owner',
            'email' => 'isolation-owner@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'restaurant_manager',
        ]);

        $restaurantA = Restaurant::create([
            'user_id' => $owner->id,
            'name' => 'View Alpha',
            'slug' => 'view-alpha',
        ]);

        $restaurantB = Restaurant::create([
            'user_id' => $owner->id,
            'name' => 'View Beta',
            'slug' => 'view-beta',
        ]);

        $categoryB = MenuCategory::create([
            'restaurant_id' => $restaurantB->id,
            'name' => 'Desserts',
            'status' => 'active',
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

        $response = $this->postJson(
            '/api/menu/' . $restaurantA->slug . '/view',
            [
                'meal_id' => $mealB->id,
                'lang' => 'en',
            ]
        );

        $response->assertStatus(422);

        $this->assertDatabaseMissing('menu_views', [
            'restaurant_id' => $restaurantA->id,
            'meal_id' => $mealB->id,
        ]);
    }
    public function test_public_menu_hides_inactive_categories(): void
    {
        $owner = User::create([
            'name' => 'Category Owner',
            'email' => 'category-owner@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'restaurant_manager',
        ]);

        $restaurant = Restaurant::create([
            'user_id' => $owner->id,
            'name' => 'Category Bistro',
            'slug' => 'category-bistro',
        ]);

        $activeCategory = MenuCategory::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Main Dishes',
            'status' => 'active',
        ]);

        MenuCategory::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Hidden Category',
            'status' => 'inactive',
        ]);

        $response = $this->getJson(
            '/api/menu/' . $restaurant->slug
        );

        $response->assertOk();

        $categories = $response->json('categories');

        $this->assertCount(1, $categories);
        $this->assertSame($activeCategory->id, $categories[0]['id']);
        $this->assertSame('Main Dishes', $categories[0]['name']);

        $response->assertJsonMissing([
            'name' => 'Hidden Category',
        ]);
    }
}
