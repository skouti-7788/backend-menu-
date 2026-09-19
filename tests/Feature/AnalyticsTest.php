<?php

namespace Tests\Feature;

use App\Models\Meal;
use App\Models\MenuCategory;
use App\Models\MenuView;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsTest extends TestCase
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

    public function test_owner_can_view_restaurant_analytics_overview(): void
    {
        [$owner, $restaurant] = $this->makeRestaurantWithOwner('a');

        Order::create([
            'restaurant_id' => $restaurant->id,
            'customer_name' => 'Customer',
            'phone' => '0600000000',
            'address' => '123 Main Street',
            'status' => 'pending',
            'total' => 10.00,
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson("/api/analytics/restaurants/{$restaurant->id}");

        $response->assertStatus(200);
        $response->assertJson([
            'restaurant_id' => $restaurant->id,
            'orders_count' => 1,
        ]);
    }

    public function test_staff_without_dashboard_view_permission_cannot_view_analytics(): void
    {
        [, $restaurant] = $this->makeRestaurantWithOwner('a');
        $staff = $this->makeStaffWithPermissions($restaurant, [], 'staffnodashboard@example.com');

        $response = $this->actingAs($staff, 'sanctum')
            ->getJson("/api/analytics/restaurants/{$restaurant->id}");

        $response->assertStatus(403);
    }

    public function test_staff_with_dashboard_view_permission_can_view_analytics(): void
    {
        [, $restaurant] = $this->makeRestaurantWithOwner('a');
        $staff = $this->makeStaffWithPermissions($restaurant, ['dashboard.view'], 'staffdashboard@example.com');

        $response = $this->actingAs($staff, 'sanctum')
            ->getJson("/api/analytics/restaurants/{$restaurant->id}");

        $response->assertStatus(200);
    }

    public function test_owner_of_one_restaurant_cannot_view_analytics_of_another_restaurant(): void
    {
        [$ownerA, ] = $this->makeRestaurantWithOwner('a');
        [, $restaurantB] = $this->makeRestaurantWithOwner('b');

        $response = $this->actingAs($ownerA, 'sanctum')
            ->getJson("/api/analytics/restaurants/{$restaurantB->id}");

        $response->assertStatus(403);
    }

    public function test_popular_meals_are_ordered_by_total_quantity_sold(): void
    {
        [$owner, $restaurant] = $this->makeRestaurantWithOwner('a');

        $category = MenuCategory::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Mains',
            'status' => 'active',
            'description' => 'desc',
        ]);

        $popularMeal = Meal::create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'name' => 'Popular Burger',
            'price' => 10,
            'status' => 'active',
            'featured' => false,
        ]);

        $unpopularMeal = Meal::create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'name' => 'Unpopular Salad',
            'price' => 8,
            'status' => 'active',
            'featured' => false,
        ]);

        $order = Order::create([
            'restaurant_id' => $restaurant->id,
            'customer_name' => 'Customer',
            'phone' => '0600000000',
            'address' => '123 Main Street',
            'status' => 'completed',
            'total' => 50,
        ]);

        $order->items()->create([
            'meal_id' => $popularMeal->id,
            'quantity' => 5,
            'unit_price' => 10,
            'total_price' => 50,
            'notes' => '',
        ]);

        $order->items()->create([
            'meal_id' => $unpopularMeal->id,
            'quantity' => 1,
            'unit_price' => 8,
            'total_price' => 8,
            'notes' => '',
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson("/api/analytics/restaurants/{$restaurant->id}/popular-meals");

        $response->assertStatus(200);
        $meals = $response->json('popular_meals');

        $this->assertSame('Popular Burger', $meals[0]['name']);
    }

    public function test_menu_views_are_grouped_by_language(): void
    {
        [$owner, $restaurant] = $this->makeRestaurantWithOwner('a');

        MenuView::create([
            'restaurant_id' => $restaurant->id,
            'language' => 'en',
        ]);

        MenuView::create([
            'restaurant_id' => $restaurant->id,
            'language' => 'en',
        ]);

        MenuView::create([
            'restaurant_id' => $restaurant->id,
            'language' => 'fr',
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson("/api/analytics/restaurants/{$restaurant->id}/menu-views");

        $response->assertStatus(200);

        $views = collect($response->json('menu_views'))->keyBy('language');

        $this->assertSame(2, $views['en']['total']);
        $this->assertSame(1, $views['fr']['total']);
    }

    public function test_unauthenticated_user_cannot_view_analytics(): void
    {
        [, $restaurant] = $this->makeRestaurantWithOwner('a');

        $response = $this->getJson("/api/analytics/restaurants/{$restaurant->id}");

        $response->assertStatus(401);
    }
}
