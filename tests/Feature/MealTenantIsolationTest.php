<?php
 
namespace Tests\Feature;
 
use App\Models\Meal;
use App\Models\MenuCategory;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
 
class MealTenantIsolationTest extends TestCase
{
    use RefreshDatabase;
 
    private function makeRestaurantWithOwner(string $emailSuffix): array
    {
        $owner = User::create([
            'name' => "Owner {$emailSuffix}",
            'email' => "owner{$emailSuffix}@example.com",
            'password' => 'secret123',
            'role' => 'owner',
        ]);
 
        $restaurant = Restaurant::create([
            'user_id' => $owner->id,
            'name' => "Restaurant {$emailSuffix}",
            'slug' => "restaurant-{$emailSuffix}",
        ]);
 
        $owner->restaurant_id = $restaurant->id;
        $owner->save();
 
        return [$owner, $restaurant];
    }
 
    public function test_staff_of_one_restaurant_cannot_list_meals_of_another_restaurant(): void
    {
        [, $restaurantA] = $this->makeRestaurantWithOwner('a');
        [$ownerB, $restaurantB] = $this->makeRestaurantWithOwner('b');
 
        $staffB = User::create([
            'name' => 'Staff B',
            'email' => 'staffb@example.com',
            'password' => 'secret123',
            'role' => 'staff',
            'restaurant_id' => $restaurantB->id,
        ]);
 
        // Even a legitimate staff member of restaurant B must not be able
        // to read restaurant A's meals by guessing/changing the URL id.
        $response = $this->actingAs($staffB, 'sanctum')
            ->getJson("/api/restaurants/{$restaurantA->id}/meals");
 
        $response->assertStatus(403);
    }
 
    public function test_owner_of_one_restaurant_cannot_update_a_meal_belonging_to_another_restaurant(): void
    {
        [$ownerA, $restaurantA] = $this->makeRestaurantWithOwner('a');
        [$ownerB, $restaurantB] = $this->makeRestaurantWithOwner('b');

        $categoryA = MenuCategory::create([
            'restaurant_id' => $restaurantA->id,
            'name' => 'Mains A',
            'status' => 'active',
        ]);

        $categoryB = MenuCategory::create([
            'restaurant_id' => $restaurantB->id,
            'name' => 'Mains B',
            'status' => 'active',
        ]);

        $mealB = Meal::create([
            'restaurant_id' => $restaurantB->id,
            'category_id' => $categoryB->id,
            'name' => 'Steak',
            'price' => 25.00,
            'status' => 'active',
            'featured' => false,
        ]);

        $response = $this->actingAs($ownerA, 'sanctum')
            ->putJson("/api/restaurants/{$restaurantA->id}/meals/{$mealB->id}", [
                'category_id' => $categoryA->id,
                'name' => 'Hacked Steak',
                'price' => 0.01,
                'status' => 'active',
            ]);

        $response->assertStatus(404);

        $this->assertDatabaseHas('meals', [
            'id' => $mealB->id,
            'name' => 'Steak',
            'price' => 25.00,
        ]);
    }
 
    public function test_owner_can_manage_meals_within_their_own_restaurant(): void
    {
        [$owner, $restaurant] = $this->makeRestaurantWithOwner('c');
 
        $category = MenuCategory::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Desserts',
            'status' => 'active',
        ]);
 
        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/restaurants/{$restaurant->id}/meals", [
                'category_id' => $category->id,
                'name' => 'Tiramisu',
                'price' => 8.50,
                'status' => 'active',
            ]);
 
        $response->assertStatus(201);
        $this->assertDatabaseHas('meals', [
            'restaurant_id' => $restaurant->id,
            'name' => 'Tiramisu',
        ]);
    }
    public function test_owner_cannot_create_a_meal_using_a_category_from_another_restaurant(): void
    {
        [$ownerA, $restaurantA] = $this->makeRestaurantWithOwner('category-a');
        [$ownerB, $restaurantB] = $this->makeRestaurantWithOwner('category-b');

        $categoryB = MenuCategory::create([
            'restaurant_id' => $restaurantB->id,
            'name' => 'Category B',
            'status' => 'active',
        ]);

        $response = $this->actingAs($ownerA, 'sanctum')
            ->postJson("/api/restaurants/{$restaurantA->id}/meals", [
                'category_id' => $categoryB->id,
                'name' => 'Invalid Cross Tenant Meal',
                'price' => 10.00,
                'status' => 'active',
            ]);

        $response->assertStatus(422);

        $this->assertDatabaseMissing('meals', [
            'restaurant_id' => $restaurantA->id,
            'name' => 'Invalid Cross Tenant Meal',
        ]);
    }
    public function test_deleting_a_meal_deactivates_it_and_preserves_order_history(): void
    {
        [$owner, $restaurant] = $this->makeRestaurantWithOwner('delete-history');

        $category = MenuCategory::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Main Dishes',
            'status' => 'active',
        ]);

        $meal = Meal::create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'name' => 'Historical Steak',
            'price' => 49.00,
            'status' => 'active',
            'featured' => false,
        ]);

        $order = Order::create([
            'restaurant_id' => $restaurant->id,
            'customer_name' => 'Test Customer',
            'phone' => '0600000000',
            'address' => 'Test Address',
            'total' => 53.41,
            'status' => 'pending',
            'table_id' => null,
            'table_token' => null,
        ]);

        $orderItem =  OrderItem::create([
            'order_id' => $order->id,
            'meal_id' => $meal->id,
            'quantity' => 1,
            'unit_price' => 49.00,
            'total_price' => 49.00,
            'notes' =>  '',
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->deleteJson(
                "/api/restaurants/{$restaurant->id}/meals/{$meal->id}"
            );

        $response->assertStatus(200);

        $this->assertDatabaseHas('meals', [
            'id' => $meal->id,
            'restaurant_id' => $restaurant->id,
            'status' => 'inactive',
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'restaurant_id' => $restaurant->id,
        ]);

        $this->assertDatabaseHas('order_items', [
            'id' => $orderItem->id,
            'order_id' => $order->id,
            'meal_id' => $meal->id,
            'quantity' => 1,
            'unit_price' => 49.00,
            'total_price' => 49.00,
        ]);
    }
    public function test_deleting_all_meals_deactivates_them_without_removing_them(): void
    {
        [$owner, $restaurant] = $this->makeRestaurantWithOwner('delete-all');

        $category = MenuCategory::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Main Dishes',
            'status' => 'active',
        ]);

        $mealA = Meal::create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'name' => 'Meal A',
            'price' => 20.00,
            'status' => 'active',
            'featured' => false,
        ]);

        $mealB = Meal::create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'name' => 'Meal B',
            'price' => 30.00,
            'status' => 'active',
            'featured' => false,
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->deleteJson(
                "/api/restaurants/{$restaurant->id}/meals/all"
            );

        $response->assertStatus(200);

        $this->assertDatabaseHas('meals', [
            'id' => $mealA->id,
            'status' => 'inactive',
        ]);

        $this->assertDatabaseHas('meals', [
            'id' => $mealB->id,
            'status' => 'inactive',
        ]);

        $this->assertDatabaseCount('meals', 2);
    }
}
 