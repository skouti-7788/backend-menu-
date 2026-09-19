<?php

namespace Tests\Feature;

use App\Models\MenuCategory;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuCategoryManagementTest extends TestCase
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

    public function test_owner_can_create_a_category(): void
    {
        [$owner, $restaurant] = $this->makeRestaurantWithOwner('a');

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/restaurants/{$restaurant->id}/categories", [
                'name' => 'Starters',
                'status' => 'active',
                'description' => 'Appetizers to start the meal',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('menu_categories', [
            'restaurant_id' => $restaurant->id,
            'name' => 'Starters',
        ]);
    }

    public function test_creating_a_category_requires_name_status_and_description(): void
    {
        [$owner, $restaurant] = $this->makeRestaurantWithOwner('a');

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/restaurants/{$restaurant->id}/categories", []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'status', 'description']);
    }

    public function test_staff_without_categories_add_permission_cannot_create_a_category(): void
    {
        [, $restaurant] = $this->makeRestaurantWithOwner('a');
        $staff = $this->makeStaffWithPermissions($restaurant, [], 'staffnoadd@example.com');

        $response = $this->actingAs($staff, 'sanctum')
            ->postJson("/api/restaurants/{$restaurant->id}/categories", [
                'name' => 'Drinks',
                'status' => 'active',
                'description' => 'Beverages',
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('menu_categories', ['name' => 'Drinks']);
    }

    public function test_staff_with_categories_add_permission_can_create_a_category(): void
    {
        [, $restaurant] = $this->makeRestaurantWithOwner('a');
        $staff = $this->makeStaffWithPermissions($restaurant, ['categories.add'], 'staffadd@example.com');

        $response = $this->actingAs($staff, 'sanctum')
            ->postJson("/api/restaurants/{$restaurant->id}/categories", [
                'name' => 'Drinks',
                'status' => 'active',
                'description' => 'Beverages',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('menu_categories', ['name' => 'Drinks']);
    }

    public function test_owner_of_one_restaurant_cannot_view_a_category_belonging_to_another_restaurant(): void
    {
        [$ownerA, $restaurantA] = $this->makeRestaurantWithOwner('a');
        [, $restaurantB] = $this->makeRestaurantWithOwner('b');

        $categoryB = MenuCategory::create([
            'restaurant_id' => $restaurantB->id,
            'name' => 'Secret Menu',
            'status' => 'active',
            'description' => 'Not for restaurant A',
        ]);

        $response = $this->actingAs($ownerA, 'sanctum')
            ->getJson("/api/restaurants/{$restaurantA->id}/categories/{$categoryB->id}");

        $response->assertStatus(404);
    }

    public function test_owner_can_update_their_own_category(): void
    {
        [$owner, $restaurant] = $this->makeRestaurantWithOwner('a');

        $category = MenuCategory::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Old Name',
            'status' => 'active',
            'description' => 'Old description',
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->putJson("/api/restaurants/{$restaurant->id}/categories/{$category->id}", [
                'name' => 'New Name',
                'status' => 'active',
                'description' => 'New description',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('menu_categories', [
            'id' => $category->id,
            'name' => 'New Name',
        ]);
    }

    public function test_owner_cannot_update_a_category_belonging_to_another_restaurant(): void
    {
        [$ownerA, $restaurantA] = $this->makeRestaurantWithOwner('a');
        [, $restaurantB] = $this->makeRestaurantWithOwner('b');

        $categoryB = MenuCategory::create([
            'restaurant_id' => $restaurantB->id,
            'name' => 'Restaurant B Category',
            'status' => 'active',
            'description' => 'Belongs to B',
        ]);

        $response = $this->actingAs($ownerA, 'sanctum')
            ->putJson("/api/restaurants/{$restaurantA->id}/categories/{$categoryB->id}", [
                'name' => 'Hacked',
                'status' => 'active',
                'description' => 'Hacked description',
            ]);

        $response->assertStatus(404);
        $this->assertDatabaseHas('menu_categories', [
            'id' => $categoryB->id,
            'name' => 'Restaurant B Category',
        ]);
    }

    public function test_staff_without_categories_delete_permission_cannot_delete_a_category(): void
    {
        [, $restaurant] = $this->makeRestaurantWithOwner('a');
        $staff = $this->makeStaffWithPermissions($restaurant, ['categories.view'], 'staffnodelete@example.com');

        $category = MenuCategory::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'To Delete',
            'status' => 'active',
            'description' => 'Should stay',
        ]);

        $response = $this->actingAs($staff, 'sanctum')
            ->deleteJson("/api/restaurants/{$restaurant->id}/categories/{$category->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('menu_categories', ['id' => $category->id]);
    }

    public function test_owner_can_delete_their_own_category(): void
    {
        [$owner, $restaurant] = $this->makeRestaurantWithOwner('a');

        $category = MenuCategory::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Delete Me',
            'status' => 'active',
            'description' => 'Temporary',
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/restaurants/{$restaurant->id}/categories/{$category->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('menu_categories', ['id' => $category->id]);
    }

    public function test_unauthenticated_user_cannot_list_categories(): void
    {
        [, $restaurant] = $this->makeRestaurantWithOwner('a');

        $response = $this->getJson("/api/restaurants/{$restaurant->id}/categories");

        $response->assertStatus(401);
    }
}
