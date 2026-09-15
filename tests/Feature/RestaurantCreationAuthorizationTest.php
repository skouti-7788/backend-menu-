<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestaurantCreationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_cannot_create_a_restaurant(): void
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'owner',
        ]);

        $restaurant = Restaurant::create([
            'user_id' => $owner->id,
            'name' => 'Existing Restaurant',
            'slug' => 'existing-restaurant',
        ]);

        $staff = User::create([
            'name' => 'Staff Member',
            'email' => 'staff@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'staff',
            'restaurant_id' => $restaurant->id,
        ]);

        $response = $this->actingAs($staff, 'sanctum')
            ->postJson('/api/restaurants', [
                'name' => 'Sneaky New Restaurant',
            ]);

        $response->assertStatus(403);

        $this->assertDatabaseMissing('restaurants', [
            'name' => 'Sneaky New Restaurant',
        ]);
    }

    public function test_owner_can_still_create_a_restaurant(): void
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner2@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'owner',
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/restaurants', [
                'name' => 'Second Restaurant',
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('restaurants', [
            'name' => 'Second Restaurant',
            'user_id' => $owner->id,
        ]);
    }
}