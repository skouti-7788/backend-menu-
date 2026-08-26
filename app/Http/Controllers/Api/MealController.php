<?php

namespace App\Http\Controllers\Api;

use App\Enums\MealStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Meal\MealRequest;
use App\Http\Resources\MealResource;
use App\Models\Meal;
use App\Models\MenuCategory;
use App\Models\Restaurant;
use App\Services\TranslationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MealController extends Controller
{
    public function index(Request $request, Restaurant $restaurant)
    {
        $this->authorizeRestaurant($restaurant);

        return MealResource::collection(
            $restaurant->meals()->with('translations')->latest()->get()
        );
    }

    public function store(MealRequest $request, Restaurant $restaurant): MealResource
    {
        $this->authorizeRestaurant($restaurant);

        $data = $request->safe()->except(['image']);
        $data['restaurant_id'] = $restaurant->id;
        $data['featured'] = $request->boolean('featured', false);

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('meals', 'public');
        }

        $category = MenuCategory::where('id', $data['category_id'])
            ->where('restaurant_id', $restaurant->id)
            ->firstOrFail();

        $meal = Meal::create($data);

        return new MealResource($meal->load('translations'));
    }

    // public function show(Request $request, Meal $meal, TranslationService $translator): MealResource
    // {
    //     $this->authorizeRestaurant($meal->restaurant);

    //     $meal->load('translations');

    //     if ($request->filled('lang')) {
    //         $translation = $translator->translateMeal($meal, $request->query('lang'));
    //         $meal->name = $translation->name;
    //         $meal->description = $translation->description;
    //         $meal->setRelation('translations', $meal->translations->push($translation));
    //     }

    //     return new MealResource($meal);
    // }

    // public function update(MealRequest $request, Meal $meal): MealResource
    // {
    //     $this->authorizeRestaurant($meal->restaurant);

    //     $data = $request->safe()->except(['image']);
    //     $data['featured'] = $request->boolean('featured', $meal->featured);

    //     if ($request->hasFile('image')) {
    //         $this->deleteFile($meal->image);
    //         $data['image'] = $request->file('image')->store('meals', 'public');
    //     }

    //     $this->ensureCategoryBelongsToRestaurant($data['category_id'], $meal->restaurant_id);

    //     $meal->update($data);

    //     return new MealResource($meal->load('translations'));
    // }

    // public function destroy(Meal $meal): JsonResponse
    // {
    //     $this->authorizeRestaurant($meal->restaurant);

    //     $this->deleteFile($meal->image);
    //     $meal->delete();

    //     return response()->json(['message' => 'Meal deleted successfully.']);
    // }
    public function show(
        Request $request,
        string $restaurant,
        string $meal,
        TranslationService $translator
    ): MealResource {
        $restaurantModel = Restaurant::findOrFail($restaurant);

        $this->authorizeRestaurant($restaurantModel);

        $mealModel = Meal::where('id', $meal)
            ->where('restaurant_id', $restaurantModel->id)
            ->firstOrFail();

        $mealModel->load('translations');

        if ($request->filled('lang')) {
            $translation = $translator->translateMeal(
                $mealModel,
                $request->query('lang')
            );

            $mealModel->name = $translation->name;
            $mealModel->description = $translation->description;
            $mealModel->setRelation(
                'translations',
                $mealModel->translations->push($translation)
            );
        }

        return new MealResource($mealModel);
    }
    public function update(
    MealRequest $request,
    string $restaurant,
    string $meal
    ): MealResource {

    $restaurantModel = Restaurant::findOrFail($restaurant);

    $this->authorizeRestaurant($restaurantModel);

    $mealModel = Meal::where('id', $meal)
        ->where('restaurant_id', $restaurantModel->id)
        ->firstOrFail();


    $data = $request->safe()->except(['image']);
    $data['featured'] = $request->boolean(
        'featured',
        $mealModel->featured
    );


    if ($request->hasFile('image')) {
        $this->deleteFile($mealModel->image);

        $data['image'] = $request->file('image')
            ->store('meals', 'public');
    }


    $this->ensureCategoryBelongsToRestaurant(
        $data['category_id'],
        $restaurantModel->id
    );


    $mealModel->update($data);


    return new MealResource(
        $mealModel->load('translations')
    );
    }
    public function destroy(
    string $restaurant,
    string $meal
    ): JsonResponse {

    $restaurantModel = Restaurant::findOrFail($restaurant);

    $this->authorizeRestaurant($restaurantModel);


    $mealModel = Meal::where('id', $meal)
        ->where('restaurant_id', $restaurantModel->id)
        ->firstOrFail();


    $this->deleteFile($mealModel->image);

    $mealModel->delete();


    return response()->json([
        'message' => 'Meal deleted successfully.'
    ]);
   }
    protected function authorizeRestaurant(Restaurant $restaurant): void
    {
        $user = auth()->user();

        if ($user->role !== 'admin' && $restaurant->user_id !== $user->id) {
            abort(403, 'You are not authorized to manage this restaurant.');
        }
    }

    protected function ensureCategoryBelongsToRestaurant(int $categoryId, int $restaurantId): void
    {
        MenuCategory::where('id', $categoryId)
            ->where('restaurant_id', $restaurantId)
            ->firstOrFail();
    }

    protected function deleteFile(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
