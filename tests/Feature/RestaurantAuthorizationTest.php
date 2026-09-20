<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestaurantAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_without_restaurant_update_permission_cannot_update_restaurant(): void
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner-update-denied@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'owner',
        ]);

        $restaurant = Restaurant::create([
            'user_id' => $owner->id,
            'name' => 'Original Restaurant',
            'slug' => 'original-restaurant',
        ]);

        $staff = User::create([
            'name' => 'Staff Member',
            'email' => 'staff-update-denied@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'staff',
            'restaurant_id' => $restaurant->id,
        ]);

        $response = $this->actingAs($staff, 'sanctum')
            ->putJson("/api/restaurants/{$restaurant->id}", [
                'name' => 'Unauthorized Update',
            ]);

        $response->assertStatus(403);

        $this->assertDatabaseHas('restaurants', [
            'id' => $restaurant->id,
            'name' => 'Original Restaurant',
        ]);
    }

    public function test_staff_with_restaurant_update_permission_can_update_restaurant(): void
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner-update-allowed@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'owner',
        ]);

        $restaurant = Restaurant::create([
            'user_id' => $owner->id,
            'name' => 'Original Restaurant',
            'slug' => 'original-restaurant',
        ]);

        $staff = User::create([
            'name' => 'Staff Member',
            'email' => 'staff-update-allowed@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'staff',
            'restaurant_id' => $restaurant->id,
        ]);

        $staff->permissions()->create([
            'permission' => 'restaurant.update',
        ]);

        $response = $this->actingAs($staff, 'sanctum')
            ->putJson("/api/restaurants/{$restaurant->id}", [
                'name' => 'Authorized Update',
            ]);

        $response->assertSuccessful();

        $this->assertDatabaseHas('restaurants', [
            'id' => $restaurant->id,
            'name' => 'Authorized Update',
        ]);
    }

    public function test_staff_cannot_delete_restaurant_even_with_update_permission(): void
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner-delete-denied@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'owner',
        ]);

        $restaurant = Restaurant::create([
            'user_id' => $owner->id,
            'name' => 'Protected Restaurant',
            'slug' => 'protected-restaurant',
        ]);

        $staff = User::create([
            'name' => 'Staff Member',
            'email' => 'staff-delete-denied@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'staff',
            'restaurant_id' => $restaurant->id,
        ]);

        $staff->permissions()->create([
            'permission' => 'restaurant.update',
        ]);

        $response = $this->actingAs($staff, 'sanctum')
            ->deleteJson("/api/restaurants/{$restaurant->id}");

        $response->assertStatus(403);

        $this->assertDatabaseHas('restaurants', [
            'id' => $restaurant->id,
            'name' => 'Protected Restaurant',
        ]);
    }
    public function test_staff_cannot_update_another_restaurant_even_with_update_permission(): void
{
    $ownerA = User::create([
        'name' => 'Owner A',
        'email' => 'owner-a@example.com',
        'password' => bcrypt('secret123'),
        'role' => 'owner',
    ]);

    $ownerB = User::create([
        'name' => 'Owner B',
        'email' => 'owner-b@example.com',
        'password' => bcrypt('secret123'),
        'role' => 'owner',
    ]);

    $restaurantA = Restaurant::create([
        'user_id' => $ownerA->id,
        'name' => 'Restaurant A',
        'slug' => 'restaurant-a',
    ]);

    $restaurantB = Restaurant::create([
        'user_id' => $ownerB->id,
        'name' => 'Restaurant B',
        'slug' => 'restaurant-b',
    ]);

    $staff = User::create([
        'name' => 'Staff A',
        'email' => 'staff-a@example.com',
        'password' => bcrypt('secret123'),
        'role' => 'staff',
        'restaurant_id' => $restaurantA->id,
    ]);

    $staff->permissions()->create([
        'permission' => 'restaurant.update',
    ]);

    $response = $this->actingAs($staff, 'sanctum')
        ->putJson("/api/restaurants/{$restaurantB->id}", [
            'name' => 'Hacked Restaurant B',
        ]);

    $response->assertStatus(403);

    $this->assertDatabaseHas('restaurants', [
        'id' => $restaurantB->id,
        'name' => 'Restaurant B',
    ]);

    $this->assertDatabaseMissing('restaurants', [
        'id' => $restaurantB->id,
        'name' => 'Hacked Restaurant B',
    ]);
}
}
