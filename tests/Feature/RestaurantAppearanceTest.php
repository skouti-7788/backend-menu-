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

        Restaurant::create([
            'user_id' => $owner->id,
            'name' => 'Beta Bistro',
            'slug' => 'beta-bistro',
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
}
