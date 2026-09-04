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
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MealController extends Controller
{
    public function index(
        Request $request,
        Restaurant $restaurant
    ) {
        $this->authorizeRestaurant($restaurant);

        return MealResource::collection(
            $restaurant->meals()
                ->with('translations')
                ->latest()
                ->get()
        );
    }

    public function store(
        MealRequest $request,
        Restaurant $restaurant
    ): MealResource {
        $this->authorizeRestaurant($restaurant);

        $data = $request->safe()->except(['image']);

        $data['restaurant_id'] = $restaurant->id;
        $data['featured'] = $request->boolean('featured', false);

        /*
        |--------------------------------------------------------------------------
        | Upload image to Cloudinary
        |--------------------------------------------------------------------------
        */

        if ($request->hasFile('image')) {
            $uploadedFile = Cloudinary::upload(
                $request->file('image')->getRealPath(),
                [
                    'folder' => 'menu-online/meals',
                    'resource_type' => 'image',
                ]
            );

            $data['image'] = $uploadedFile->getSecurePath();
            $data['image_public_id'] = $uploadedFile->getPublicId();
        }

        /*
        |--------------------------------------------------------------------------
        | Verify category belongs to restaurant
        |--------------------------------------------------------------------------
        */

        MenuCategory::where('id', $data['category_id'])
            ->where('restaurant_id', $restaurant->id)
            ->firstOrFail();

        /*
        |--------------------------------------------------------------------------
        | Create meal
        |--------------------------------------------------------------------------
        */

        $meal = Meal::create($data);

        return new MealResource(
            $meal->load('translations')
        );
    }

    public function show(
        Request $request,
        string $restaurant,
        string $meal,
        TranslationService $translator
    ): MealResource {
        /*
        |--------------------------------------------------------------------------
        | Find restaurant
        |--------------------------------------------------------------------------
        */

        $restaurantModel = Restaurant::findOrFail($restaurant);

        $this->authorizeRestaurant($restaurantModel);

        /*
        |--------------------------------------------------------------------------
        | Find meal belonging to restaurant
        |--------------------------------------------------------------------------
        */

        $mealModel = Meal::where('id', $meal)
            ->where('restaurant_id', $restaurantModel->id)
            ->firstOrFail();

        $mealModel->load('translations');

        /*
        |--------------------------------------------------------------------------
        | Translation
        |--------------------------------------------------------------------------
        */

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
        /*
        |--------------------------------------------------------------------------
        | Find restaurant
        |--------------------------------------------------------------------------
        */

        $restaurantModel = Restaurant::findOrFail($restaurant);

        $this->authorizeRestaurant($restaurantModel);

        /*
        |--------------------------------------------------------------------------
        | Find meal belonging to restaurant
        |--------------------------------------------------------------------------
        */

        $mealModel = Meal::where('id', $meal)
            ->where('restaurant_id', $restaurantModel->id)
            ->firstOrFail();

        /*
        |--------------------------------------------------------------------------
        | Get validated data
        |--------------------------------------------------------------------------
        */

        $data = $request->safe()->except(['image']);

        $data['featured'] = $request->boolean(
            'featured',
            $mealModel->featured
        );

        /*
        |--------------------------------------------------------------------------
        | Replace image
        |--------------------------------------------------------------------------
        */

        if ($request->hasFile('image')) {

            /*
            |--------------------------------------------------------------------------
            | Delete old Cloudinary image
            |--------------------------------------------------------------------------
            */

            $this->deleteCloudinaryImage(
                $mealModel->image_public_id
            );

            /*
            |--------------------------------------------------------------------------
            | Upload new image
            |--------------------------------------------------------------------------
            */

            $uploadedFile = Cloudinary::upload(
                $request->file('image')->getRealPath(),
                [
                    'folder' => 'menu-online/meals',
                    'resource_type' => 'image',
                ]
            );

            $data['image'] = $uploadedFile->getSecurePath();
            $data['image_public_id'] = $uploadedFile->getPublicId();
        }

        /*
        |--------------------------------------------------------------------------
        | Verify category belongs to restaurant
        |--------------------------------------------------------------------------
        */

        $this->ensureCategoryBelongsToRestaurant(
            $data['category_id'],
            $restaurantModel->id
        );

        /*
        |--------------------------------------------------------------------------
        | Update meal
        |--------------------------------------------------------------------------
        */

        $mealModel->update($data);

        return new MealResource(
            $mealModel->load('translations')
        );
    }

    public function destroy(
        string $restaurant,
        string $meal
    ): JsonResponse {
        /*
        |--------------------------------------------------------------------------
        | Find restaurant
        |--------------------------------------------------------------------------
        */

        $restaurantModel = Restaurant::findOrFail($restaurant);

        $this->authorizeRestaurant($restaurantModel);

        /*
        |--------------------------------------------------------------------------
        | Find meal belonging to restaurant
        |--------------------------------------------------------------------------
        */

        $mealModel = Meal::where('id', $meal)
            ->where('restaurant_id', $restaurantModel->id)
            ->firstOrFail();

        /*
        |--------------------------------------------------------------------------
        | Delete Cloudinary image
        |--------------------------------------------------------------------------
        */

        $this->deleteCloudinaryImage(
            $mealModel->image_public_id
        );

        /*
        |--------------------------------------------------------------------------
        | Delete meal
        |--------------------------------------------------------------------------
        */

        $mealModel->delete();

        return response()->json([
            'message' => 'Meal deleted successfully.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */

    protected function authorizeRestaurant(
        Restaurant $restaurant
    ): void {
        $user = auth()->user();

        if (
            $user->role !== 'admin' &&
            $restaurant->user_id !== $user->id
        ) {
            abort(
                403,
                'You are not authorized to manage this restaurant.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Category validation
    |--------------------------------------------------------------------------
    */

    protected function ensureCategoryBelongsToRestaurant(
        int $categoryId,
        int $restaurantId
    ): void {
        MenuCategory::where('id', $categoryId)
            ->where('restaurant_id', $restaurantId)
            ->firstOrFail();
    }

    /*
    |--------------------------------------------------------------------------
    | Delete Cloudinary image
    |--------------------------------------------------------------------------
    */

    protected function deleteCloudinaryImage(
        ?string $publicId
    ): void {
        if (!$publicId) {
            return;
        }

        try {
            Cloudinary::destroy($publicId);
        } catch (\Throwable $e) {
            \Log::error(
                'Cloudinary image deletion failed.',
                [
                    'public_id' => $publicId,
                    'error' => $e->getMessage(),
                ]
            );
        }
    }
}