<?php

namespace Tests\Feature;

use App\Models\Meal;
use App\Models\MenuCategory;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentTranslationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function createOwnerAndRestaurant(string $suffix = 'one'): array
    {
        $owner = User::create([
            'name' => 'Translation Owner',
            'email' => "translation-owner-{$suffix}@example.com",
            'password' => bcrypt('secret123'),
            'role' => 'restaurant_manager',
        ]);

        $restaurant = Restaurant::create([
            'user_id' => $owner->id,
            'name' => 'Restaurant '.$suffix,
            'slug' => 'restaurant-'.$suffix,
        ]);

        return [$owner, $restaurant];
    }

    public function test_owner_can_save_manual_meal_translation(): void
    {
        [$owner, $restaurant] = $this->createOwnerAndRestaurant();
        $category = MenuCategory::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Main dishes',
            'status' => 'active',
        ]);
        $meal = Meal::create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'name' => 'Chicken tagine',
            'description' => 'Chicken with olives',
            'price' => 45,
            'status' => 'active',
            'featured' => false,
        ]);

        $response = $this->actingAs($owner)->putJson(
            "/api/restaurants/{$restaurant->id}/meals/{$meal->id}/translations/fr",
            ['name' => 'Tajine de poulet', 'description' => 'Poulet aux olives']
        );

        $response->assertOk()->assertJsonPath('translation.is_manual', true);
        $this->assertDatabaseHas('meal_translations', [
            'meal_id' => $meal->id,
            'language' => 'fr',
            'name' => 'Tajine de poulet',
            'is_manual' => true,
            'source_hash' => null,
        ]);
    }

    public function test_owner_can_save_manual_category_and_restaurant_translations(): void
    {
        [$owner, $restaurant] = $this->createOwnerAndRestaurant('two');
        $category = MenuCategory::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Desserts',
            'status' => 'active',
        ]);

        $categoryResponse = $this->actingAs($owner)->putJson(
            "/api/restaurants/{$restaurant->id}/categories/{$category->id}/translations/ar",
            ['name' => 'حلويات', 'description' => 'أطباق حلوة']
        );
        $categoryResponse->assertOk()->assertJsonPath('translation.is_manual', true);

        $restaurantResponse = $this->actingAs($owner)->putJson(
            "/api/restaurants/{$restaurant->id}/translations/fr",
            ['name' => 'Mon restaurant', 'description' => 'Cuisine familiale']
        );
        $restaurantResponse->assertOk()->assertJsonPath('translation.is_manual', true);

        $this->assertDatabaseHas('menu_category_translations', [
            'menu_category_id' => $category->id,
            'language' => 'ar',
            'is_manual' => true,
        ]);
        $this->assertDatabaseHas('restaurant_translations', [
            'restaurant_id' => $restaurant->id,
            'language' => 'fr',
            'is_manual' => true,
        ]);
    }

    public function test_user_cannot_edit_translation_for_another_restaurant(): void
    {
        [$owner, $restaurant] = $this->createOwnerAndRestaurant('three');
        [, $otherRestaurant] = $this->createOwnerAndRestaurant('four');
        $category = MenuCategory::create([
            'restaurant_id' => $otherRestaurant->id,
            'name' => 'Other category',
            'status' => 'active',
        ]);
        $meal = Meal::create([
            'restaurant_id' => $otherRestaurant->id,
            'category_id' => $category->id,
            'name' => 'Other meal',
            'price' => 10,
            'status' => 'active',
            'featured' => false,
        ]);

        $response = $this->actingAs($owner)->putJson(
            "/api/restaurants/{$restaurant->id}/meals/{$meal->id}/translations/fr",
            ['name' => 'Intrusion', 'description' => 'Not allowed']
        );

        $response->assertNotFound();
        $this->assertDatabaseMissing('meal_translations', [
            'meal_id' => $meal->id,
            'language' => 'fr',
        ]);
    }

    public function test_translation_routes_require_authentication(): void
    {
        [$owner, $restaurant] = $this->createOwnerAndRestaurant('five');
        $response = $this->putJson(
            "/api/restaurants/{$restaurant->id}/translations/fr",
            ['name' => 'Unauthorized', 'description' => 'No token']
        );

        $response->assertUnauthorized();
    }

    public function test_manual_translation_rejects_unsupported_language(): void
    {
        [$owner, $restaurant] = $this->createOwnerAndRestaurant('six');
        $response = $this->actingAs($owner)->putJson(
            "/api/restaurants/{$restaurant->id}/translations/de",
            ['name' => 'Restaurant', 'description' => 'Description']
        );

        $response->assertUnprocessable();
    }
}
