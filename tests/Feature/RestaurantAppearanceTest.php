<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestaurantAppearanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_owner_can_fetch_default_appearance(): void
    {
        $owner = User::create([
            'name' => 'Owner One',
            'email' => 'owner1@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'restaurant_manager',
        ]);

        $restaurant = Restaurant::create([
            'user_id' => $owner->id,
            'name' => 'Alpha Bistro',
            'slug' => 'alpha-bistro',
        ]);

        $restaurant->subscriptions()->create([
            'plan' => 'business',
            'billing_interval' => 'monthly',
            'status' => 'active',
            'amount' => 199,
            'currency' => 'MAD',
            'starts_at' => now(),
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/restaurant/appearance');

        $response->assertOk()
            ->assertJsonPath('restaurant_id', $restaurant->id)
            ->assertJsonPath('primary_color', '#D97706')
            ->assertJsonPath('secondary_color', '#92400E')
            ->assertJsonPath('text_color', '#1F2937')
            ->assertJsonPath('background_color', '#FFFFFF')
            ->assertJsonPath('font_family', 'Inter');
    }

    public function test_authenticated_owner_can_update_appearance_for_their_own_restaurant(): void
    {
        $owner = User::create([
            'name' => 'Owner Two',
            'email' => 'owner2@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'restaurant_manager',
        ]);

        $restaurant = Restaurant::create([
            'user_id' => $owner->id,
            'name' => 'Beta Bistro',
            'slug' => 'beta-bistro',
        ]);

        $restaurant->subscriptions()->create([
            'plan' => 'business',
            'billing_interval' => 'monthly',
            'status' => 'active',
            'amount' => 199,
            'currency' => 'MAD',
            'starts_at' => now(),
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/restaurant/appearance', [
                'primary_color' => '#123456',
                'secondary_color' => '#654321',
                'text_color' => '#111111',
                'background_color' => '#F9FAFB',
                'font_family' => 'Poppins',
            ]);

        $response->assertOk()
            ->assertJsonPath('appearance.primary_color', '#123456')
            ->assertJsonPath('appearance.secondary_color', '#654321')
            ->assertJsonPath('appearance.text_color', '#111111')
            ->assertJsonPath('appearance.background_color', '#F9FAFB')
            ->assertJsonPath('appearance.font_family', 'Poppins');
    }

    public function test_starter_plan_cannot_remove_advanced_header_image(): void
    {
        $owner = User::create([
            'name' => 'Starter Owner',
            'email' => 'starter-appearance@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'restaurant_manager',
        ]);

        $restaurant = Restaurant::create([
            'user_id' => $owner->id,
            'name' => 'Starter Bistro',
            'slug' => 'starter-bistro',
        ]);

        $restaurant->subscriptions()->create([
            'plan' => 'starter',
            'billing_interval' => 'monthly',
            'status' => 'active',
            'amount' => 0,
            'currency' => 'MAD',
            'starts_at' => now(),
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/restaurant/appearance', [
                'primary_color' => '#123456',
                'font_family' => 'Poppins',
                'header_image' => 'not-allowed-on-starter',
                'background_image' => 'not-allowed-on-starter',
                'remove_header_image' => true,
                'remove_background_image' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'primary_color',
                'font_family',
                'header_image',
                'background_image',
                'remove_header_image',
                'remove_background_image',
            ]);
    }

    public function test_starter_plan_can_still_access_basic_appearance_settings(): void
    {
        $owner = User::create([
            'name' => 'Starter Basic Owner',
            'email' => 'starter-basic-appearance@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'restaurant_manager',
        ]);

        $restaurant = Restaurant::create([
            'user_id' => $owner->id,
            'name' => 'Starter Basic Bistro',
            'slug' => 'starter-basic-bistro',
        ]);

        $restaurant->subscriptions()->create([
            'plan' => 'starter',
            'billing_interval' => 'monthly',
            'status' => 'active',
            'amount' => 0,
            'currency' => 'MAD',
            'starts_at' => now(),
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/restaurant/appearance', [])
            ->assertOk()
            ->assertJsonPath('appearance.restaurant_id', $restaurant->id);
    }

}
