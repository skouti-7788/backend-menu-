<?php

namespace Tests\Feature;

use App\Models\MenuCategory;
use App\Models\Meal;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Brute-force protection: after 6 wrong login attempts in a minute,
     * the 7th attempt must be blocked (HTTP 429), even with correct credentials.
     */
    public function test_login_is_rate_limited_after_six_attempts(): void
    {
        $user = User::create([
            'name' => 'Test Owner',
            'email' => 'owner@example.com',
            'password' => Hash::make('correct-password'),
            'role' => 'owner',
        ]);

        for ($i = 0; $i < 6; $i++) {
            $response = $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);

            $response->assertStatus(401);
        }

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertStatus(429);
    }

    /**
     * Prevents scripted mass account creation via /auth/register.
     */
    public function test_register_is_rate_limited_after_six_attempts(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $response = $this->postJson('/api/auth/register', [
                'name' => "Bot User {$i}",
                'email' => "bot{$i}@example.com",
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ]);

            $this->assertNotEquals(429, $response->status());
        }

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Bot User 7',
            'email' => 'bot7@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(429);
    }

    /**
     * Public menu endpoint must be protected against excessive requests.
     */
    public function test_public_menu_is_rate_limited_after_sixty_requests(): void
    {
        $owner = User::create([
            'name' => 'Menu Rate Owner',
            'email' => 'menu-rate@example.com',
            'password' => Hash::make('secret123'),
            'role' => 'restaurant_manager',
        ]);

        $restaurant = Restaurant::create([
            'user_id' => $owner->id,
            'name' => 'Rate Limited Bistro',
            'slug' => 'rate-limited-bistro',
        ]);

        for ($i = 0; $i < 60; $i++) {
            $response = $this->getJson('/api/menu/' . $restaurant->slug);

            $this->assertNotEquals(
                429,
                $response->status(),
                "Request {$i} was unexpectedly rate limited."
            );
        }

        $response = $this->getJson('/api/menu/' . $restaurant->slug);

        $response->assertStatus(429);
    }

    /**
     * Public menu view tracking must be rate limited.
     */
    public function test_public_menu_view_is_rate_limited_after_thirty_requests(): void
    {
        $owner = User::create([
            'name' => 'View Rate Owner',
            'email' => 'view-rate@example.com',
            'password' => Hash::make('secret123'),
            'role' => 'restaurant_manager',
        ]);

        $restaurant = Restaurant::create([
            'user_id' => $owner->id,
            'name' => 'View Rate Bistro',
            'slug' => 'view-rate-bistro',
        ]);

        for ($i = 0; $i < 30; $i++) {
            $response = $this->postJson(
                '/api/menu/' . $restaurant->slug . '/view'
            );

            $this->assertNotEquals(
                429,
                $response->status(),
                "Request {$i} was unexpectedly rate limited."
            );
        }

        $response = $this->postJson(
            '/api/menu/' . $restaurant->slug . '/view'
        );

        $response->assertStatus(429);
    }

    /**
     * Public order creation must be rate limited to reduce order spam.
     */
    public function test_public_orders_are_rate_limited_after_ten_requests(): void
    {
        $owner = User::create([
            'name' => 'Order Rate Owner',
            'email' => 'order-rate@example.com',
            'password' => Hash::make('secret123'),
            'role' => 'restaurant_manager',
        ]);

        $restaurant = Restaurant::create([
            'user_id' => $owner->id,
            'name' => 'Order Rate Bistro',
            'slug' => 'order-rate-bistro',
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

        $table = RestaurantTable::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Table 1',
            'number' => 1,
            'status' => 'available',
        ]);

        $payload = [
            'customer_name' => 'Test Customer',
            'phone' => '0600000000',
            'address' => 'Table 1',
            'table_token' => $table->qr_token,
            'items' => [
                [
                    'meal_id' => $meal->id,
                    'quantity' => 1,
                ],
            ],
        ];

        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson(
                '/api/menu/' . $restaurant->slug . '/orders',
                $payload
            );

            $this->assertNotEquals(
                429,
                $response->status(),
                "Request {$i} was unexpectedly rate limited."
            );
        }

        $response = $this->postJson(
            '/api/menu/' . $restaurant->slug . '/orders',
            $payload
        );

        $response->assertStatus(429);
    }
}