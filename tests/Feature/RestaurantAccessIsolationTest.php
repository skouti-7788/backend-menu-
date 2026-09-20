<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestaurantAccessIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function createOwner(
        string $email,
        string $name = 'Owner'
    ): User {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('secret123'),
            'role' => 'owner',
        ]);
    }

    private function createRestaurant(
        User $owner,
        string $name,
        string $slug
    ): Restaurant {
        return Restaurant::create([
            'user_id' => $owner->id,
            'name' => $name,
            'slug' => $slug,
        ]);
    }

    public function test_owner_cannot_show_another_owners_restaurant(): void
    {
        $ownerA = $this->createOwner('owner-a@example.com');
        $ownerB = $this->createOwner('owner-b@example.com');

        $restaurantA = $this->createRestaurant(
            $ownerA,
            'Restaurant A',
            'restaurant-a'
        );

        $restaurantB = $this->createRestaurant(
            $ownerB,
            'Restaurant B',
            'restaurant-b'
        );

        $response = $this->actingAs($ownerA, 'sanctum')
            ->getJson("/api/restaurants/{$restaurantB->id}");

        $response->assertStatus(403);
    }

    public function test_staff_cannot_show_another_restaurants_restaurant(): void
    {
        $ownerA = $this->createOwner('owner-a@example.com');
        $ownerB = $this->createOwner('owner-b@example.com');

        $restaurantA = $this->createRestaurant(
            $ownerA,
            'Restaurant A',
            'restaurant-a'
        );

        $restaurantB = $this->createRestaurant(
            $ownerB,
            'Restaurant B',
            'restaurant-b'
        );

        $staff = User::create([
            'name' => 'Staff',
            'email' => 'staff@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'staff',
            'restaurant_id' => $restaurantA->id,
        ]);

        $response = $this->actingAs($staff, 'sanctum')
            ->getJson("/api/restaurants/{$restaurantB->id}");

        $response->assertStatus(403);
    }

    public function test_staff_index_returns_only_their_restaurant(): void
    {
        $ownerA = $this->createOwner('owner-a@example.com');
        $ownerB = $this->createOwner('owner-b@example.com');

        $restaurantA = $this->createRestaurant(
            $ownerA,
            'Restaurant A',
            'restaurant-a'
        );

        $restaurantB = $this->createRestaurant(
            $ownerB,
            'Restaurant B',
            'restaurant-b'
        );

        $staff = User::create([
            'name' => 'Staff',
            'email' => 'staff@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'staff',
            'restaurant_id' => $restaurantA->id,
        ]);

        $response = $this->actingAs($staff, 'sanctum')
            ->getJson('/api/restaurants');

        $response->assertOk();

        $response->assertJsonCount(1, 'data');

        $response->assertJsonPath(
            'data.0.id',
            $restaurantA->id
        );

        $response->assertJsonMissing([
            'id' => $restaurantB->id,
        ]);
    }

    public function test_owner_index_returns_only_restaurants_they_own(): void
    {
        $ownerA = $this->createOwner('owner-a@example.com');
        $ownerB = $this->createOwner('owner-b@example.com');

        $restaurantA = $this->createRestaurant(
            $ownerA,
            'Restaurant A',
            'restaurant-a'
        );

        $restaurantB = $this->createRestaurant(
            $ownerB,
            'Restaurant B',
            'restaurant-b'
        );

        $response = $this->actingAs($ownerA, 'sanctum')
            ->getJson('/api/restaurants');

        $response->assertOk();

        $response->assertJsonCount(1, 'data');

        $response->assertJsonPath(
            'data.0.id',
            $restaurantA->id
        );

        $response->assertJsonMissing([
            'id' => $restaurantB->id,
        ]);
    }

    public function test_staff_cannot_delete_another_restaurants_restaurant(): void
    {
        $ownerA = $this->createOwner('owner-a@example.com');
        $ownerB = $this->createOwner('owner-b@example.com');

        $restaurantA = $this->createRestaurant(
            $ownerA,
            'Restaurant A',
            'restaurant-a'
        );

        $restaurantB = $this->createRestaurant(
            $ownerB,
            'Restaurant B',
            'restaurant-b'
        );

        $staff = User::create([
            'name' => 'Staff',
            'email' => 'staff@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'staff',
            'restaurant_id' => $restaurantA->id,
        ]);

        $response = $this->actingAs($staff, 'sanctum')
            ->deleteJson("/api/restaurants/{$restaurantB->id}");

        $response->assertStatus(403);

        $this->assertDatabaseHas('restaurants', [
            'id' => $restaurantB->id,
        ]);
    }
}
