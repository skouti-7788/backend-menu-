<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Meal;
use App\Models\MenuCategory;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentTranslationController extends Controller
{
    private const LANGUAGES = ['en', 'fr', 'ar'];

    private function validatedTranslation(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $data['description'] = $data['description'] ?? '';

        return $data;
    }

    private function validateLanguage(string $language): void
    {
        abort_unless(in_array($language, self::LANGUAGES, true), 422, 'Unsupported language.');
    }

    public function updateMeal(Request $request, Restaurant $restaurant, Meal $meal, string $language): JsonResponse
    {
        $this->requirePermission($restaurant, 'meals.update');
        abort_unless((int) $meal->restaurant_id === (int) $restaurant->id, 404);
        $this->validateLanguage($language);
        $data = $this->validatedTranslation($request);

        $translation = $meal->translations()->updateOrCreate(
            ['language' => $language],
            [...$data, 'is_manual' => true, 'source_hash' => null]
        );

        return response()->json(['message' => 'Translation saved.', 'translation' => $translation]);
    }

    public function updateCategory(Request $request, Restaurant $restaurant, MenuCategory $category, string $language): JsonResponse
    {
        $this->requirePermission($restaurant, 'categories.update');
        abort_unless((int) $category->restaurant_id === (int) $restaurant->id, 404);
        $this->validateLanguage($language);
        $data = $this->validatedTranslation($request);

        $translation = $category->translations()->updateOrCreate(
            ['language' => $language],
            [...$data, 'is_manual' => true, 'source_hash' => null]
        );

        return response()->json(['message' => 'Translation saved.', 'translation' => $translation]);
    }

    public function updateRestaurant(Request $request, Restaurant $restaurant, string $language): JsonResponse
    {
        $this->requirePermission($restaurant, 'restaurant.update');
        $this->validateLanguage($language);
        $data = $this->validatedTranslation($request);

        $translation = $restaurant->translations()->updateOrCreate(
            ['language' => $language],
            [...$data, 'is_manual' => true, 'source_hash' => null]
        );

        return response()->json(['message' => 'Translation saved.', 'translation' => $translation]);
    }

    public function showMeal(Restaurant $restaurant, Meal $meal): JsonResponse
    {
        $this->requirePermission($restaurant, 'meals.update');
        abort_unless((int) $meal->restaurant_id === (int) $restaurant->id, 404);

        return response()->json([
            'translations' => $meal->translations()->get(['language', 'name', 'description', 'is_manual']),
        ]);
    }

    public function showCategory(Restaurant $restaurant, MenuCategory $category): JsonResponse
    {
        $this->requirePermission($restaurant, 'categories.update');
        abort_unless((int) $category->restaurant_id === (int) $restaurant->id, 404);

        return response()->json([
            'translations' => $category->translations()->get(['language', 'name', 'description', 'is_manual']),
        ]);
    }

    public function showRestaurant(Restaurant $restaurant): JsonResponse
    {
        $this->requirePermission($restaurant, 'restaurant.update');

        return response()->json([
            'translations' => $restaurant->translations()->get(['language', 'name', 'description', 'is_manual']),
        ]);
    }
}
