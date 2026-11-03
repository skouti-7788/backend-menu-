<?php

namespace Tests\Feature;

use App\Models\Meal;
use App\Models\MenuCategory;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContentTranslationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function createMenu(string $suffix): array
    {
        $owner = User::create([
            'name' => 'Automatic Translation Owner',
            'email' => "automatic-translation-{$suffix}@example.com",
            'password' => bcrypt('secret123'),
            'role' => 'restaurant_manager',
        ]);
        $restaurant = Restaurant::create([
            'user_id' => $owner->id,
            'name' => "Restaurant {$suffix}",
            'description' => 'Traditional Moroccan food',
            'slug' => "restaurant-{$suffix}",
        ]);
        $category = MenuCategory::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Main dishes',
            'description' => 'Traditional dishes',
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
        return [$restaurant, $category, $meal];
    }

    private function fakeAutomaticProvider(): void
    {
        config(['services.translation.url' => 'https://api.mymemory.translated.net/get']);
        Http::fake(fn ($request) => Http::response([
            'responseStatus' => 200,
            'responseData' => [
                'translatedText' => 'AUTO '.$request['q'],
                'detectedLanguage' => 'en',
            ],
        ]));
    }

    public function test_public_menu_automatically_translates_and_persists_all_content_types(): void
    {
        $this->fakeAutomaticProvider();
        [$restaurant, $category, $meal] = $this->createMenu('automatic');

        $this->getJson("/api/menu/{$restaurant->slug}?lang=fr")
            ->assertOk()
            ->assertJsonPath('restaurant.name', 'AUTO Restaurant automatic')
            ->assertJsonPath('categories.0.name', 'AUTO Main dishes')
            ->assertJsonPath('meals.0.name', 'AUTO Chicken tagine');

        $this->assertDatabaseHas('restaurant_translations', [
            'restaurant_id' => $restaurant->id, 'language' => 'fr',
            'name' => 'AUTO Restaurant automatic', 'is_manual' => false,
        ]);
        $this->assertDatabaseHas('menu_category_translations', [
            'menu_category_id' => $category->id, 'language' => 'fr',
            'name' => 'AUTO Main dishes', 'is_manual' => false,
        ]);
        $this->assertDatabaseHas('meal_translations', [
            'meal_id' => $meal->id, 'language' => 'fr',
            'name' => 'AUTO Chicken tagine', 'is_manual' => false,
        ]);
    }

    public function test_automatic_translation_replaces_legacy_manual_rows(): void
    {
        $this->fakeAutomaticProvider();
        [$restaurant, $category, $meal] = $this->createMenu('legacy');

        foreach ([
            ['restaurant_translations', 'restaurant_id', $restaurant->id, 'Old manual restaurant'],
            ['menu_category_translations', 'menu_category_id', $category->id, 'Old manual category'],
            ['meal_translations', 'meal_id', $meal->id, 'Old manual meal'],
        ] as [$table, $foreignKey, $id, $oldName]) {
            DB::table($table)->insert([
                $foreignKey => $id, 'language' => 'fr', 'name' => $oldName,
                'description' => 'Old manual description', 'is_manual' => true,
                'source_hash' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->getJson("/api/menu/{$restaurant->slug}?lang=fr")
            ->assertOk()
            ->assertJsonPath('restaurant.name', 'AUTO Restaurant legacy')
            ->assertJsonPath('categories.0.name', 'AUTO Main dishes')
            ->assertJsonPath('meals.0.name', 'AUTO Chicken tagine');

        $this->assertDatabaseHas('restaurant_translations', [
            'restaurant_id' => $restaurant->id, 'language' => 'fr',
            'name' => 'AUTO Restaurant legacy', 'is_manual' => false,
        ]);
        $this->assertDatabaseHas('menu_category_translations', [
            'menu_category_id' => $category->id, 'language' => 'fr',
            'name' => 'AUTO Main dishes', 'is_manual' => false,
        ]);
        $this->assertDatabaseHas('meal_translations', [
            'meal_id' => $meal->id, 'language' => 'fr',
            'name' => 'AUTO Chicken tagine', 'is_manual' => false,
        ]);
    }

    public function test_public_menu_keeps_original_content_when_provider_fails(): void
    {
        config(['services.translation.url' => 'https://api.mymemory.translated.net/get']);
        Http::fake(['api.mymemory.translated.net/get*' => Http::response([
            'responseStatus' => '403',
            'responseDetails' => 'Quota exceeded',
            'responseData' => ['translatedText' => 'QUOTA EXCEEDED'],
        ])]);
        [$restaurant, $category, $meal] = $this->createMenu('fallback');

        $this->getJson("/api/menu/{$restaurant->slug}?lang=fr")
            ->assertOk()
            ->assertJsonPath('restaurant.name', 'Restaurant fallback')
            ->assertJsonPath('categories.0.name', 'Main dishes')
            ->assertJsonPath('meals.0.name', 'Chicken tagine');
    }
}
