<?php

namespace Tests\Feature;

use App\Models\Meal;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderManagementTest extends TestCase
{
    use RefreshDatabase;

    private function makeRestaurantWithOwner(string $suffix): array
    {
        $owner = User::create([
            'name' => "Owner {$suffix}",
            'email' => "owner{$suffix}@example.com",
            'password' => 'secret123',
            'role' => 'owner',
        ]);

        $restaurant = Restaurant::create([
            'user_id' => $owner->id,
            'name' => "Restaurant {$suffix}",
            'slug' => "restaurant-{$suffix}",
        ]);

        $owner->restaurant_id = $restaurant->id;
        $owner->save();

        return [$owner, $restaurant];
    }

    private function makeMeal(Restaurant $restaurant, float $price = 10.00): Meal
    {
        $category = MenuCategory::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Mains',
            'status' => 'active',
            'description' => 'Main dishes',
        ]);

        return Meal::create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'name' => 'Burger',
            'price' => $price,
            'status' => 'active',
            'featured' => false,
        ]);
    }

    private function makeStaffWithPermissions(Restaurant $restaurant, array $permissions, string $email): User
    {
        $staff = User::create([
            'name' => 'Staff ' . $email,
            'email' => $email,
            'password' => 'secret123',
            'role' => 'staff',
            'restaurant_id' => $restaurant->id,
        ]);

        foreach ($permissions as $permission) {
            UserPermission::create([
                'user_id' => $staff->id,
                'permission' => $permission,
            ]);
        }

        return $staff;
    }

    public function test_owner_can_create_an_order_with_items_and_total_is_calculated(): void
    {
        [$owner, $restaurant] = $this->makeRestaurantWithOwner('a');
        $meal = $this->makeMeal($restaurant, 10.00);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/restaurants/{$restaurant->id}/orders", [
                'customer_name' => 'John Doe',
                'phone' => '0600000000',
                'address' => '123 Main Street',
                'items' => [
                    ['meal_id' => $meal->id, 'quantity' => 2],
                ],
            ]);

        $response->assertStatus(201);

        // subtotal 20.00 + 9% tax = 21.80
        $this->assertDatabaseHas('orders', [
            'restaurant_id' => $restaurant->id,
            'customer_name' => 'John Doe',
            'total' => 21.80,
        ]);

        $this->assertDatabaseHas('order_items', [
            'meal_id' => $meal->id,
            'quantity' => 2,
            'unit_price' => 10.00,
        ]);
    }

    public function test_order_creation_rejects_a_meal_from_another_restaurant(): void
    {
        [$ownerA, $restaurantA] =
            $this->makeRestaurantWithOwner('a');

        [, $restaurantB] =
            $this->makeRestaurantWithOwner('b');

        $mealB =
            $this->makeMeal(
                $restaurantB,
                15.00
            );

        $response =
            $this->actingAs(
                $ownerA,
                'sanctum'
            )->postJson(
                "/api/restaurants/{$restaurantA->id}/orders",
                [
                    'customer_name' => 'Jane Doe',
                    'address' => '456 Side Street',

                    'items' => [
                        [
                            'meal_id' => $mealB->id,
                            'quantity' => 1,
                        ],
                    ],
                ]
            );

        $response->assertStatus(422);

        $this->assertDatabaseMissing('orders', [
            'restaurant_id' => $restaurantA->id,
            'customer_name' => 'Jane Doe',
        ]);
    }

    public function test_staff_without_orders_view_permission_cannot_list_orders(): void
    {
        [, $restaurant] = $this->makeRestaurantWithOwner('a');
        $staff = $this->makeStaffWithPermissions($restaurant, [], 'staffnoview@example.com');

        $response = $this->actingAs($staff, 'sanctum')
            ->getJson("/api/restaurants/{$restaurant->id}/orders");

        $response->assertStatus(403);
    }

    public function test_staff_with_orders_view_permission_can_list_orders(): void
    {
        [$owner, $restaurant] = $this->makeRestaurantWithOwner('a');
        $staff = $this->makeStaffWithPermissions($restaurant, ['orders.view'], 'staffview@example.com');

        Order::create([
            'restaurant_id' => $restaurant->id,
            'customer_name' => 'Test Customer',
            'phone' => '0600000000',
            'address' => '123 Main Street',
            'status' => 'pending',
            'total' => 12.00,
        ]);

        $response = $this->actingAs($staff, 'sanctum')
            ->getJson("/api/restaurants/{$restaurant->id}/orders");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }

    public function test_staff_of_one_restaurant_does_not_see_orders_belonging_to_another_restaurant(): void
    {
        [, $restaurantA] = $this->makeRestaurantWithOwner('a');
        [, $restaurantB] = $this->makeRestaurantWithOwner('b');

        $staffA = $this->makeStaffWithPermissions($restaurantA, ['orders.view'], 'staffa@example.com');

        Order::create([
            'restaurant_id' => $restaurantB->id,
            'customer_name' => 'Someone Else',
            'phone' => '0600000000',
            'address' => '123 Main Street',
            'status' => 'pending',
            'total' => 30.00,
        ]);

        $response = $this->actingAs($staffA, 'sanctum')
            ->getJson("/api/restaurants/{$restaurantA->id}/orders");

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data');
    }

    public function test_owner_can_update_order_status_and_table_becomes_reserved(): void
    {
        [$owner, $restaurant] = $this->makeRestaurantWithOwner('a');

        $table = RestaurantTable::create([
            'restaurant_id' => $restaurant->id,
            'number' => 1,
            'name' => 'Table 1',
            'status' => 'available',
        ]);

        $order = Order::create([
            'restaurant_id' => $restaurant->id,
            'customer_name' => 'Dine In',
            'phone' => '0600000000',
            'address' => '123 Main Street',
            'status' => 'pending',
            'total' => 15.00,
            'table_id' => $table->id,
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->putJson("/api/orders/{$order->id}/status", [
                'status' => 'preparing',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('restaurant_tables', [
            'id' => $table->id,
            'status' => 'reserved',
        ]);
    }

    public function test_owner_can_complete_order_and_table_becomes_available_again(): void
    {
        [$owner, $restaurant] = $this->makeRestaurantWithOwner('a');

        $table = RestaurantTable::create([
            'restaurant_id' => $restaurant->id,
            'number' => 1,
            'name' => 'Table 1',
            'status' => 'reserved',
        ]);

        $order = Order::create([
            'restaurant_id' => $restaurant->id,
            'customer_name' => 'Dine In',
            'phone' => '0600000000',
            'address' => '123 Main Street',
            'status' => 'preparing',
            'total' => 15.00,
            'table_id' => $table->id,
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->putJson("/api/orders/{$order->id}/status", [
                'status' => 'completed',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('restaurant_tables', [
            'id' => $table->id,
            'status' => 'available',
        ]);
    }

    public function test_owner_of_one_restaurant_cannot_update_status_of_another_restaurants_order(): void
    {
        [$ownerA, ] = $this->makeRestaurantWithOwner('a');
        [, $restaurantB] = $this->makeRestaurantWithOwner('b');

        $order = Order::create([
            'restaurant_id' => $restaurantB->id,
            'customer_name' => 'Owner B customer',
            'phone' => '0600000000',
            'address' => '123 Main Street',
            'status' => 'pending',
            'total' => 10.00,
        ]);

        $response = $this->actingAs($ownerA, 'sanctum')
            ->putJson("/api/orders/{$order->id}/status", [
                'status' => 'completed',
            ]);

        $response->assertStatus(403);
    }

    public function test_staff_without_orders_delete_permission_cannot_delete_an_order(): void
    {
        [, $restaurant] = $this->makeRestaurantWithOwner('a');
        $staff = $this->makeStaffWithPermissions($restaurant, ['orders.view'], 'staffnodelete@example.com');

        $order = Order::create([
            'restaurant_id' => $restaurant->id,
            'customer_name' => 'To Delete',
            'phone' => '0600000000',
            'address' => '123 Main Street',
            'status' => 'pending',
            'total' => 10.00,
        ]);

        $response = $this->actingAs($staff, 'sanctum')
            ->deleteJson("/api/orders/{$order->id}/delete");

        $response->assertStatus(403);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_owner_can_delete_an_order_and_free_up_the_table(): void
    {
        [$owner, $restaurant] = $this->makeRestaurantWithOwner('a');

        $table = RestaurantTable::create([
            'restaurant_id' => $restaurant->id,
            'number' => 1,
            'name' => 'Table 1',
            'status' => 'reserved',
        ]);

        $order = Order::create([
            'restaurant_id' => $restaurant->id,
            'customer_name' => 'To Delete',
            'phone' => '0600000000',
            'address' => '123 Main Street',
            'status' => 'pending',
            'total' => 10.00,
            'table_id' => $table->id,
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/orders/{$order->id}/delete");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
        $this->assertDatabaseHas('restaurant_tables', [
            'id' => $table->id,
            'status' => 'available',
        ]);
    }

    public function test_updating_order_status_rejects_an_invalid_status_value(): void
    {
        [$owner, $restaurant] = $this->makeRestaurantWithOwner('a');

        $order = Order::create([
            'restaurant_id' => $restaurant->id,
            'customer_name' => 'Test',
            'phone' => '0600000000',
            'address' => '123 Main Street',
            'status' => 'pending',
            'total' => 10.00,
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->putJson("/api/orders/{$order->id}/status", [
                'status' => 'teleported',
            ]);

        $response->assertStatus(422);
    }
    public function test_order_creation_rejects_a_table_from_another_restaurant(): void
    {
        [$ownerA, $restaurantA] =
            $this->makeRestaurantWithOwner('table-a');

        [, $restaurantB] =
            $this->makeRestaurantWithOwner('table-b');

        $mealA =
            $this->makeMeal(
                $restaurantA,
                10.00
            );

        $tableB = RestaurantTable::create([
            'restaurant_id' => $restaurantB->id,
            'number' => 1,
            'name' => 'Table B',
            'status' => 'available',
        ]);

        $response =
            $this->actingAs(
                $ownerA,
                'sanctum'
            )->postJson(
                "/api/restaurants/{$restaurantA->id}/orders",
                [
                    'customer_name' => 'Cross Tenant Customer',
                    'address' => '123 Main Street',

                    'table_id' => $tableB->id,

                    'items' => [
                        [
                            'meal_id' => $mealA->id,
                            'quantity' => 1,
                        ],
                    ],
                ]
            );

        $response->assertStatus(422);

        $this->assertDatabaseMissing('orders', [
            'restaurant_id' => $restaurantA->id,
            'customer_name' => 'Cross Tenant Customer',
        ]);
    }
    public function test_dashboard_order_creation_reserves_the_selected_table(): void
    {
        [$owner, $restaurant] = $this->makeRestaurantWithOwner('dashboard-table');

        $meal = $this->makeMeal($restaurant, 18.50);

        $table = RestaurantTable::create([
            'restaurant_id' => $restaurant->id,
            'number' => 1,
            'name' => 'Table 1',
            'status' => 'available',
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/restaurants/{$restaurant->id}/orders", [
                'customer_name' => 'Dashboard Customer',
                'phone' => '0600000000',
                'address' => '123 Main Street',
                'table_id' => $table->id,
                'items' => [
                    [
                        'meal_id' => $meal->id,
                        'quantity' => 2,
                    ],
                ],
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('orders', [
            'restaurant_id' => $restaurant->id,
            'table_id' => $table->id,
            'status' => 'pending',
            'total' => 40.33,
        ]);

        $this->assertDatabaseHas('restaurant_tables', [
            'id' => $table->id,
            'status' => 'reserved',
        ]);

        $this->assertDatabaseHas('order_items', [
            'order_id' => Order::where('restaurant_id', $restaurant->id)
                ->where('customer_name', 'Dashboard Customer')
                ->value('id'),
            'meal_id' => $meal->id,
            'quantity' => 2,
            'unit_price' => 18.50,
            'total_price' => 37.00,
        ]);
    }
    public function test_order_ignores_client_supplied_prices_and_calculates_from_database(): void
    {
        [$owner, $restaurant] = $this->makeRestaurantWithOwner('price-tampering');

        $meal = $this->makeMeal($restaurant, 18.50);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/restaurants/{$restaurant->id}/orders", [
                'customer_name' => 'Tampering Test',
                'phone' => '0600000000',
                'address' => '123 Main Street',

                'items' => [
                    [
                        'meal_id' => $meal->id,
                        'quantity' => 2,

                        // Malicious client-controlled values.
                        'unit_price' => 0.01,
                        'total_price' => 0.02,
                    ],
                ],

                // Malicious client-controlled total.
                'total' => 0.02,
                'tax' => 0,
            ]);

        $response->assertStatus(201);

        // Real DB price: 18.50 × 2 = 37.00
        // Tax: 3.33
        // Total: 40.33
        $this->assertDatabaseHas('order_items', [
            'meal_id' => $meal->id,
            'quantity' => 2,
            'unit_price' => 18.50,
            'total_price' => 37.00,
        ]);

        $this->assertDatabaseHas('orders', [
            'restaurant_id' => $restaurant->id,
            'total' => 40.33,
        ]);
    }
}
