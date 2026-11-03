<?php

namespace Tests\Feature;

use App\Models\Meal;
use App\Models\MenuCategory;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_public_menu_does_not_overwrite_a_manual_meal_translation(): void
    {
        config(['services.translation.url' => 'https://api.mymemory.translated.net/get']);
        Http::fake([
            'translation.test/translate' => Http::response([
                'translatedText' => 'Automatically translated text',
                'detectedLanguage' => ['language' => 'en'],
            ]),
        ]);

        [$owner, $restaurant] = $this->createOwnerAndRestaurant('manual-protected');
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

        $this->actingAs($owner)->putJson(
            "/api/restaurants/{$restaurant->id}/meals/{$meal->id}/translations/fr",
            ['name' => 'Tajine de poulet corrigé', 'description' => 'Poulet aux olives']
        )->assertOk();

        $response = $this->getJson("/api/menu/{$restaurant->slug}?lang=fr");

        $response->assertOk()
            ->assertJsonPath('meals.0.name', 'Tajine de poulet corrigé')
            ->assertJsonPath('meals.0.description', 'Poulet aux olives');

        $this->assertDatabaseHas('meal_translations', [
            'meal_id' => $meal->id,
            'language' => 'fr',
            'name' => 'Tajine de poulet corrigé',
            'is_manual' => true,
            'source_hash' => null,
        ]);
    }

    public function test_owner_can_read_saved_meal_category_and_restaurant_translations(): void
    {
        [$owner, $restaurant] = $this->createOwnerAndRestaurant('read-translations');
        $category = MenuCategory::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Desserts',
            'status' => 'active',
        ]);
        $meal = Meal::create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'name' => 'Cake',
            'price' => 20,
            'status' => 'active',
            'featured' => false,
        ]);

        $this->actingAs($owner)->putJson(
            "/api/restaurants/{$restaurant->id}/meals/{$meal->id}/translations/fr",
            ['name' => 'Gâteau', 'description' => 'Gâteau au chocolat']
        )->assertOk();
        $this->actingAs($owner)->putJson(
            "/api/restaurants/{$restaurant->id}/categories/{$category->id}/translations/ar",
            ['name' => 'حلويات', 'description' => '']
        )->assertOk();
        $this->actingAs($owner)->putJson(
            "/api/restaurants/{$restaurant->id}/translations/en",
            ['name' => 'My restaurant', 'description' => 'Family food']
        )->assertOk();

        $this->getJson("/api/restaurants/{$restaurant->id}/meals/{$meal->id}/translations")
            ->assertOk()
            ->assertJsonPath('translations.0.language', 'fr')
            ->assertJsonPath('translations.0.name', 'Gâteau');
        $this->getJson("/api/restaurants/{$restaurant->id}/categories/{$category->id}/translations")
            ->assertOk()
            ->assertJsonPath('translations.0.language', 'ar')
            ->assertJsonPath('translations.0.name', 'حلويات');
        $this->getJson("/api/restaurants/{$restaurant->id}/translations")
            ->assertOk()
            ->assertJsonPath('translations.0.language', 'en')
            ->assertJsonPath('translations.0.name', 'My restaurant');
    }



    public function test_public_menu_translates_text_without_changing_prices_contact_or_address(): void
    {
        config(['services.translation.url' => 'https://api.mymemory.translated.net/get']);
        Http::fake([
            'api.mymemory.translated.net/get*' => Http::response([
                'responseStatus' => 200,
                'responseData' => [
                    'translatedText' => 'Texte traduit',
                    'detectedLanguage' => 'en',
                ],
            ]),
        ]);

        [$owner, $restaurant] = $this->createOwnerAndRestaurant('non-text-unchanged');
        $restaurant->update([
            'address' => '12 Avenue Hassan II, Agadir',
            'phone' => '+212600000000',
        ]);

        $category = MenuCategory::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Main dishes',
            'description' => 'Traditional food',
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

        $response = $this->getJson("/api/menu/{$restaurant->slug}?lang=fr");

        $response->assertOk()
            ->assertJsonPath('restaurant.address', '12 Avenue Hassan II, Agadir')
            ->assertJsonPath('restaurant.phone', '+212600000000')
            ->assertJsonPath('meals.0.price', '45.00')
            ->assertJsonPath('meals.0.name', 'Texte traduit')
            ->assertJsonPath('categories.0.name', 'Texte traduit');

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://api.mymemory.translated.net/get')
                && $request['langpair'] === 'autodetect|fr'
                && isset($request['q'])
                && ! is_numeric($request['q']);
        });
    }


    public function test_backend_detects_source_language_and_uses_auto_translation_for_all_menu_content(): void
    {
        config(['services.translation.url' => 'https://api.mymemory.translated.net/get']);
        Http::fake(function ($request) {
            return Http::response([
                'responseStatus' => 200,
                'responseData' => [
                    'translatedText' => 'Incorrect duplicate translation',
                    'detectedLanguage' => 'fr',
                ],
            ]);
        });

        [, $restaurant] = $this->createOwnerAndRestaurant('auto-language-detection');
        $restaurant->update([
            'name' => 'Restaurant français',
            'description' => 'Cuisine traditionnelle',
        ]);

        $category = MenuCategory::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Plats principaux',
            'description' => 'Cuisine marocaine',
            'status' => 'active',
        ]);

        Meal::create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'name' => 'Tajine de poulet',
            'description' => 'Poulet aux olives',
            'price' => 45,
            'status' => 'active',
            'featured' => false,
        ]);

        $response = $this->getJson("/api/menu/{$restaurant->slug}?lang=en");

        $response->assertOk()
            ->assertJsonPath('restaurant.name', 'Incorrect duplicate translation')
            ->assertJsonPath('categories.0.name', 'Incorrect duplicate translation')
            ->assertJsonPath('meals.0.name', 'Incorrect duplicate translation');

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://api.mymemory.translated.net/get')
                && $request['langpair'] === 'autodetect|en'
                && $request['mt'] === '1';
        });
    }

    public function test_public_menu_refreshes_automatic_translations_when_source_text_changes(): void
    {
        config(['services.translation.url' => 'https://api.mymemory.translated.net/get']);
        Http::fake(function ($request) {
            return Http::response([
                'responseStatus' => 200,
                'responseData' => [
                    'translatedText' => 'FR '.$request['q'],
                    'detectedLanguage' => 'en',
                ],
            ]);
        });

        [, $restaurant] = $this->createOwnerAndRestaurant('source-refresh');
        $category = MenuCategory::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Desserts',
            'description' => 'Sweet dishes',
            'status' => 'active',
        ]);
        $meal = Meal::create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'name' => 'Chocolate cake',
            'description' => 'Dark chocolate',
            'price' => 25,
            'status' => 'active',
            'featured' => false,
        ]);

        $this->getJson("/api/menu/{$restaurant->slug}?lang=fr")
            ->assertOk()
            ->assertJsonPath('restaurant.name', 'FR Restaurant source-refresh')
            ->assertJsonPath('categories.0.name', 'FR Desserts')
            ->assertJsonPath('meals.0.name', 'FR Chocolate cake');

        $restaurant->update(['name' => 'Updated restaurant']);
        $category->update(['name' => 'New desserts']);
        $meal->update(['name' => 'Updated chocolate cake']);

        $this->getJson("/api/menu/{$restaurant->slug}?lang=fr")
            ->assertOk()
            ->assertJsonPath('restaurant.name', 'FR Updated restaurant')
            ->assertJsonPath('categories.0.name', 'FR New desserts')
            ->assertJsonPath('meals.0.name', 'FR Updated chocolate cake');

        $this->assertDatabaseHas('restaurant_translations', [
            'restaurant_id' => $restaurant->id,
            'language' => 'fr',
            'name' => 'FR Updated restaurant',
            'is_manual' => false,
        ]);
        $this->assertDatabaseHas('menu_category_translations', [
            'menu_category_id' => $category->id,
            'language' => 'fr',
            'name' => 'FR New desserts',
            'is_manual' => false,
        ]);
        $this->assertDatabaseHas('meal_translations', [
            'meal_id' => $meal->id,
            'language' => 'fr',
            'name' => 'FR Updated chocolate cake',
            'is_manual' => false,
        ]);
    }

    public function test_owner_can_delete_cleared_manual_translations_and_restore_auto_fallback(): void
    {
        [$owner, $restaurant] = $this->createOwnerAndRestaurant('delete-translations');
        $category = MenuCategory::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Desserts',
            'status' => 'active',
        ]);
        $meal = Meal::create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'name' => 'Cake',
            'price' => 20,
            'status' => 'active',
            'featured' => false,
        ]);

        $this->actingAs($owner)->putJson(
            "/api/restaurants/{$restaurant->id}/meals/{$meal->id}/translations/fr",
            ['name' => 'Gâteau', 'description' => 'Au chocolat']
        )->assertOk();
        $this->actingAs($owner)->putJson(
            "/api/restaurants/{$restaurant->id}/categories/{$category->id}/translations/ar",
            ['name' => 'حلويات', 'description' => '']
        )->assertOk();
        $this->actingAs($owner)->putJson(
            "/api/restaurants/{$restaurant->id}/translations/en",
            ['name' => 'My restaurant', 'description' => 'Family food']
        )->assertOk();

        $this->actingAs($owner)->deleteJson(
            "/api/restaurants/{$restaurant->id}/meals/{$meal->id}/translations/fr"
        )->assertOk();
        $this->actingAs($owner)->deleteJson(
            "/api/restaurants/{$restaurant->id}/categories/{$category->id}/translations/ar"
        )->assertOk();
        $this->actingAs($owner)->deleteJson(
            "/api/restaurants/{$restaurant->id}/translations/en"
        )->assertOk();

        $this->assertDatabaseMissing('meal_translations', [
            'meal_id' => $meal->id,
            'language' => 'fr',
        ]);
        $this->assertDatabaseMissing('menu_category_translations', [
            'menu_category_id' => $category->id,
            'language' => 'ar',
        ]);
        $this->assertDatabaseMissing('restaurant_translations', [
            'restaurant_id' => $restaurant->id,
            'language' => 'en',
        ]);
    }


    public function test_public_menu_preserves_manual_restaurant_and_category_translations_after_source_changes(): void
    {
        config(['services.translation.url' => 'https://api.mymemory.translated.net/get']);
        Http::fake(function ($request) {
            return Http::response([
                'translatedText' => 'AUTO '.$request['q'],
                'detectedLanguage' => ['language' => 'en'],
            ]);
        });

        [$owner, $restaurant] = $this->createOwnerAndRestaurant('manual-source-protection');
        $category = MenuCategory::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Desserts',
            'description' => 'Sweet dishes',
            'status' => 'active',
        ]);
        $meal = Meal::create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'name' => 'Chocolate cake',
            'description' => 'Dark chocolate',
            'price' => 25,
            'status' => 'active',
            'featured' => false,
        ]);

        $this->actingAs($owner)->putJson(
            "/api/restaurants/{$restaurant->id}/translations/fr",
            ['name' => 'Nom manuel du restaurant', 'description' => 'Description manuelle']
        )->assertOk();
        $this->actingAs($owner)->putJson(
            "/api/restaurants/{$restaurant->id}/categories/{$category->id}/translations/fr",
            ['name' => 'Desserts traduits manuellement', 'description' => 'Description de catégorie']
        )->assertOk();

        $restaurant->update(['name' => 'Changed restaurant source']);
        $category->update(['name' => 'Changed category source']);

        $this->getJson("/api/menu/{$restaurant->slug}?lang=fr")
            ->assertOk()
            ->assertJsonPath('restaurant.name', 'Nom manuel du restaurant')
            ->assertJsonPath('restaurant.description', 'Description manuelle')
            ->assertJsonPath('categories.0.name', 'Desserts traduits manuellement')
            ->assertJsonPath('categories.0.description', 'Description de catégorie');

        $this->assertDatabaseHas('restaurant_translations', [
            'restaurant_id' => $restaurant->id,
            'language' => 'fr',
            'name' => 'Nom manuel du restaurant',
            'is_manual' => true,
        ]);
        $this->assertDatabaseHas('menu_category_translations', [
            'menu_category_id' => $category->id,
            'language' => 'fr',
            'name' => 'Desserts traduits manuellement',
            'is_manual' => true,
        ]);
    }

}
