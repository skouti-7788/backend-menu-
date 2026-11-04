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
            'address' => '12 Rue des Fleurs',
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
            ->assertJsonPath('restaurant.description', 'AUTO Traditional Moroccan food')
            ->assertJsonPath('categories.0.name', 'AUTO Main dishes')
            ->assertJsonPath('categories.0.description', 'AUTO Traditional dishes')
            ->assertJsonPath('meals.0.name', 'AUTO Chicken tagine')
            ->assertJsonPath('meals.0.description', 'AUTO Chicken with olives');

        $this->assertDatabaseHas('restaurant_translations', [
            'restaurant_id' => $restaurant->id, 'language' => 'fr',
            'name' => 'AUTO Restaurant automatic', 'is_manual' => false,
            'description' => 'AUTO Traditional Moroccan food',
        ]);
        $this->assertDatabaseHas('menu_category_translations', [
            'menu_category_id' => $category->id, 'language' => 'fr',
            'name' => 'AUTO Main dishes', 'is_manual' => false,
            'description' => 'AUTO Traditional dishes',
        ]);
        $this->assertDatabaseHas('meal_translations', [
            'meal_id' => $meal->id, 'language' => 'fr',
            'name' => 'AUTO Chicken tagine', 'is_manual' => false,
            'description' => 'AUTO Chicken with olives',
        ]);
    }

    public function test_automatic_translation_preserves_manual_rows(): void
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
                'source_hash' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->getJson("/api/menu/{$restaurant->slug}?lang=fr")
            ->assertOk()
            ->assertJsonPath('restaurant.name', 'Old manual restaurant')
            ->assertJsonPath('categories.0.name', 'Old manual category')
            ->assertJsonPath('meals.0.name', 'Old manual meal');

        $this->assertDatabaseHas('restaurant_translations', [
            'restaurant_id' => $restaurant->id, 'language' => 'fr',
            'name' => 'Old manual restaurant', 'is_manual' => true,
        ]);
        $this->assertDatabaseHas('menu_category_translations', [
            'menu_category_id' => $category->id, 'language' => 'fr',
            'name' => 'Old manual category', 'is_manual' => true,
        ]);
        $this->assertDatabaseHas('meal_translations', [
            'meal_id' => $meal->id, 'language' => 'fr',
            'name' => 'Old manual meal', 'is_manual' => true,
        ]);
        Http::assertNothingSent();
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

    public function test_source_updates_regenerate_automatic_translations_without_changing_originals(): void
    {
        $this->fakeAutomaticProvider();
        [$restaurant, $category, $meal] = $this->createMenu('updated');

        $this->getJson("/api/menu/{$restaurant->slug}?lang=fr")
            ->assertOk()
            ->assertJsonPath('restaurant.name', 'AUTO Restaurant updated');

        $restaurant->update(['name' => 'Updated restaurant']);
        $category->update(['name' => 'Updated category']);
        $meal->update(['name' => 'Updated meal']);

        $this->getJson("/api/menu/{$restaurant->slug}?lang=fr")
            ->assertOk()
            ->assertJsonPath('restaurant.name', 'AUTO Updated restaurant')
            ->assertJsonPath('categories.0.name', 'AUTO Updated category')
            ->assertJsonPath('meals.0.name', 'AUTO Updated meal');

        $this->assertSame('Updated restaurant', $restaurant->fresh()->name);
        $this->assertSame('Updated category', $category->fresh()->name);
        $this->assertSame('Updated meal', $meal->fresh()->name);
        $this->assertDatabaseHas('restaurant_translations', [
            'restaurant_id' => $restaurant->id,
            'language' => 'fr',
            'source_hash' => hash('sha256', json_encode([
                'name' => 'Updated restaurant',
                'description' => 'Traditional Moroccan food',
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
        ]);
    }

    public function test_partial_provider_failure_does_not_replace_a_stale_automatic_translation(): void
    {
        config(['services.translation.url' => 'https://api.mymemory.translated.net/get']);
        [$restaurant, $category, $meal] = $this->createMenu('partial-failure');
        $restaurant->translations()->create([
            'language' => 'fr',
            'name' => 'Old translated name',
            'description' => 'Old translated description',
            'is_manual' => false,
            'source_hash' => str_repeat('0', 64),
        ]);
        $category->translations()->create([
            'language' => 'fr',
            'name' => 'Old translated category',
            'description' => 'Old translated description',
            'is_manual' => false,
            'source_hash' => str_repeat('0', 64),
        ]);
        $meal->translations()->create([
            'language' => 'fr',
            'name' => 'Old translated meal',
            'description' => 'Old translated description',
            'is_manual' => false,
            'source_hash' => str_repeat('0', 64),
        ]);
        Http::fake(fn ($request) => str_contains((string) $request['q'], 'Traditional')
            || str_contains((string) $request['q'], 'with olives')
            ? Http::response(['responseStatus' => '403'])
            : Http::response([
                'responseStatus' => 200,
                'responseData' => [
                    'translatedText' => 'Translated '.$request['q'],
                    'detectedLanguage' => 'en',
                ],
            ]));

        $this->getJson("/api/menu/{$restaurant->slug}?lang=fr")
            ->assertOk()
            ->assertJsonPath('restaurant.name', 'Translated Restaurant partial-failure')
            ->assertJsonPath('restaurant.description', 'Traditional Moroccan food')
            ->assertJsonPath('categories.0.description', 'Traditional dishes')
            ->assertJsonPath('meals.0.description', 'Chicken with olives');

        $this->assertDatabaseHas('restaurant_translations', [
            'restaurant_id' => $restaurant->id,
            'language' => 'fr',
            'name' => 'Old translated name',
            'description' => 'Old translated description',
            'source_hash' => str_repeat('0', 64),
        ]);
        $this->assertDatabaseHas('menu_category_translations', [
            'menu_category_id' => $category->id,
            'language' => 'fr',
            'name' => 'Old translated category',
            'description' => 'Old translated description',
            'source_hash' => str_repeat('0', 64),
        ]);
        $this->assertDatabaseHas('meal_translations', [
            'meal_id' => $meal->id,
            'language' => 'fr',
            'name' => 'Old translated meal',
            'description' => 'Old translated description',
            'source_hash' => str_repeat('0', 64),
        ]);
    }

    public function test_public_menu_excludes_inactive_categories_and_their_meals(): void
    {
        $this->fakeAutomaticProvider();
        [$restaurant, $category, $meal] = $this->createMenu('inactive');
        $category->update(['status' => 'inactive']);

        $this->getJson("/api/menu/{$restaurant->slug}?lang=fr")
            ->assertOk()
            ->assertJsonCount(0, 'categories')
            ->assertJsonCount(0, 'meals');
    }

    public function test_public_menu_defaults_to_english_and_rejects_unsupported_languages(): void
    {
        $this->fakeAutomaticProvider();
        [$restaurant] = $this->createMenu('default-language');

        $this->getJson("/api/menu/{$restaurant->slug}")
            ->assertOk()
            ->assertJsonPath('restaurant.name', 'Restaurant default-language')
            ->assertJsonPath('restaurant.address', '12 Rue des Fleurs');

        Http::assertSent(fn ($request) => $request['langpair'] === 'autodetect|en');

        Http::fake();
        $this->getJson("/api/menu/{$restaurant->slug}?lang=es")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lang');
        Http::assertNothingSent();
    }

    public function test_another_owner_cannot_modify_a_restaurants_source_content(): void
    {
        [$restaurant] = $this->createMenu('tenant-isolation');
        $otherOwner = User::create([
            'name' => 'Different Owner',
            'email' => 'different-owner@example.com',
            'password' => bcrypt('secret123'),
            'role' => 'restaurant_manager',
        ]);

        $this->actingAs($otherOwner)
            ->putJson("/api/restaurants/{$restaurant->id}", ['name' => 'Unauthorized change'])
            ->assertForbidden();

        $this->assertSame('Restaurant tenant-isolation', $restaurant->fresh()->name);
    }

    public function test_public_menu_does_not_expose_provider_credentials(): void
    {
        $this->fakeAutomaticProvider();
        config(['services.translation.key' => 'private-provider-key']);
        [$restaurant] = $this->createMenu('credential-safe');

        $this->getJson("/api/menu/{$restaurant->slug}?lang=fr")
            ->assertOk()
            ->assertDontSee('private-provider-key');
    }

    public function test_public_menu_excludes_inactive_meals(): void
    {
        $this->fakeAutomaticProvider();
        [$restaurant, , $meal] = $this->createMenu('inactive-meal');
        $meal->update(['status' => 'inactive']);

        $this->getJson("/api/menu/{$restaurant->slug}?lang=fr")
            ->assertOk()
            ->assertJsonCount(1, 'categories')
            ->assertJsonCount(0, 'meals');
    }
}
