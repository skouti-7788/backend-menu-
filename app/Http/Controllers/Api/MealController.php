<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Meal\MealRequest;
use App\Http\Resources\MealResource;
use App\Models\Meal;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Enums\MealStatus;
use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;

class MealController extends Controller
{
    public function index(
        Request $request,
        Restaurant $restaurant
    ) {
        $this->requirePermission(
            $restaurant,
            'meals.view'
        );

        $meals = $restaurant
            ->meals()
            ->with('translations')
            ->latest()
            ->get();

        return MealResource::collection($meals);
    }

    public function store(
        MealRequest $request,
        Restaurant $restaurant
    ): MealResource {
        $this->requirePermission(
            $restaurant,
            'meals.add'
        );

        $data = $request
            ->safe()
            ->except(['image']);

        $data['restaurant_id'] = $restaurant->id;

        /*
        |--------------------------------------------------------------------------
        | Upload image to Cloudinary
        |--------------------------------------------------------------------------
        */

        if ($request->hasFile('image')) {

            $uploadedFile = Cloudinary::upload(
                $request
                    ->file('image')
                    ->getRealPath(),
                [
                    'folder' => sprintf(
                        'menu-online/restaurants/%d/meals',
                        $restaurant->id
                    ),
                    'resource_type' => 'image',
                ]
            );

            $secureUrl = $uploadedFile->getSecurePath();
            $publicId = $uploadedFile->getPublicId();

            if (! $secureUrl || ! $publicId) {
                throw new \RuntimeException(
                    'Cloudinary did not return the required image data.'
                );
            }

            $data['image'] = $secureUrl;
            $data['image_public_id'] = $publicId;
        }

        $meal = Meal::create($data);

        $meal->load('translations');

        return new MealResource($meal);
    }

    public function show(Request $request, string $slug, TranslationService $translator): JsonResponse
    {
        $validated = $request->validate([
            'lang' => ['nullable', 'string', 'in:en,fr,ar'],
        ]);

        $language = $validated['lang'] ?? 'en';

        $restaurant = Restaurant::where('slug', $slug)
            ->with(['categories', 'meals.translations'])
            ->firstOrFail();

        $meals = $restaurant->meals
            ->filter(fn ($meal) => $meal->status->value === 'active');

        $meals = $meals->map(function ($meal) use ($language, $translator) {
            if ($language !== 'en') {
                $translation = $translator->translateMeal($meal, $language);
                $meal->name = $translation->name;
                $meal->description = $translation->description;
            }

            return [
                'id' => $meal->id,
                'category_id' => $meal->category_id,
                'name' => $meal->name,
                'description' => $meal->description,
                'price' => $meal->price,
                'image_url' => $meal->image_url,
                'featured' => $meal->featured,
            ];
        });

        $appearance = $restaurant->appearance;

        return response()->json([
            'restaurant' => [
                'id' => $restaurant->id,
                'slug' => $restaurant->slug,
                'name' => $restaurant->name,
                'description' => $restaurant->description,
                'address' => $restaurant->address,
                'phone' => $restaurant->phone,
                'email' => $restaurant->email,
                'opening_hours' => $restaurant->opening_hours,
                'social_links' => $restaurant->social_links,
                'logo_url' => $restaurant->logo_url,
                'cover_image_url' => $restaurant->cover_image_url,
                'menu_url' => $restaurant->menu_url,
            ],

            'appearance' => [
                'id' => $appearance?->id,
                'restaurant_id' => $restaurant->id,
                'logo' => $appearance?->logo,
                'header_image' => $appearance?->header_image,
                'background_image' => $appearance?->background_image,
                'primary_color' => $appearance?->primary_color ?? '#D97706',
                'secondary_color' => $appearance?->secondary_color ?? '#92400E',
                'text_color' => $appearance?->text_color ?? '#1F2937',
                'background_color' => $appearance?->background_color ?? '#FFFFFF',
                'font_family' => $appearance?->font_family ?? 'Inter',
            ],

            'categories' => $restaurant->categories->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
                'image_url' => $category->image
                    ? url('storage/'.$category->image)
                    : null,
            ]),

            'meals' => $meals,

            'tables' => $restaurant->tables()
                ->orderBy('number')
                ->get()
                ->map(fn (RestaurantTable $table) => [
                    'id' => $table->id,
                    'name' => $table->name,
                    'number' => $table->number,
                    'status' => $table->status,
                ]),
        ]);
    }     

    public function update(
        MealRequest $request,
        Restaurant $restaurant,
        Meal $meal
    ): MealResource {
        $this->requirePermission(
            $restaurant,
            'meals.update'
        );

        $this->ensureMealBelongsToRestaurant(
            $meal,
            $restaurant
        );

        $data = $request
            ->safe()
            ->except(['image']);

        /*
        |--------------------------------------------------------------------------
        | Upload new image FIRST
        |--------------------------------------------------------------------------
        |
        | We keep the old image until the new upload succeeds.
        |
        */

        if ($request->hasFile('image')) {

            $uploadedFile = Cloudinary::upload(
                $request
                    ->file('image')
                    ->getRealPath(),
                [
                    'folder' => sprintf(
                        'menu-online/restaurants/%d/meals',
                        $restaurant->id
                    ),
                    'resource_type' => 'image',
                ]
            );

            $newImageUrl = $uploadedFile->getSecurePath();
            $newPublicId = $uploadedFile->getPublicId();

            if (! $newImageUrl || ! $newPublicId) {
                throw new \RuntimeException(
                    'Cloudinary did not return the required image data.'
                );
            }

            $oldImage = $meal->image;
            $oldPublicId = $meal->image_public_id;

            $data['image'] = $newImageUrl;
            $data['image_public_id'] = $newPublicId;

            $meal->update($data);

            /*
            |--------------------------------------------------------------------------
            | Delete old Cloudinary image AFTER successful DB update
            |--------------------------------------------------------------------------
            */

            $this->deleteMealImage(
                $oldImage,
                $oldPublicId
            );
        } else {
            $meal->update($data);
        }

        $meal->refresh();
        $meal->load('translations');

        return new MealResource($meal);
    }

    // public function destroy(
    //     Request $request,
    //     Restaurant $restaurant,
    //     Meal $meal
    // ): JsonResponse {
    //     $this->requirePermission(
    //         $restaurant,
    //         'meals.delete'
    //     );

    //     $this->ensureMealBelongsToRestaurant(
    //         $meal,
    //         $restaurant
    //     );

    //     $image = $meal->image;
    //     $publicId = $meal->image_public_id;

    //     $meal->delete();

    //     /*
    //     |--------------------------------------------------------------------------
    //     | Delete image after deleting the meal
    //     |--------------------------------------------------------------------------
    //     */

    //     $this->deleteMealImage(
    //         $image,
    //         $publicId
    //     );

    //     return response()->json([
    //         'message' => 'Meal deleted successfully.',
    //     ]);
    // }
    public function destroy(
        Request $request,
        Restaurant $restaurant,
        Meal $meal
    ): JsonResponse {
        $this->requirePermission(
            $restaurant,
            'meals.delete'
        );

        $this->ensureMealBelongsToRestaurant(
            $meal,
            $restaurant
        );

        $meal->update([
            'status' => MealStatus::INACTIVE,
        ]);

        return response()->json([
            'message' => 'Meal deleted successfully.',
        ]);
    }
    // public function deleteAllMeals(
    //     Request $request,
    //     Restaurant $restaurant
    // ): JsonResponse {
    //     $this->requirePermission(
    //         $restaurant,
    //         'meals.delete'
    //     );

    //     $meals = $restaurant
    //         ->meals()
    //         ->get([
    //             'id',
    //             'image',
    //             'image_public_id',
    //         ]);

    //     /*
    //     |--------------------------------------------------------------------------
    //     | Delete database records
    //     |--------------------------------------------------------------------------
    //     */

    //     $restaurant
    //         ->meals()
    //         ->delete();

    //     /*
    //     |--------------------------------------------------------------------------
    //     | Delete Cloudinary images
    //     |--------------------------------------------------------------------------
    //     */

    //     foreach ($meals as $meal) {
    //         $this->deleteMealImage(
    //             $meal->image,
    //             $meal->image_public_id
    //         );
    //     }

    //     return response()->json([
    //         'message' => 'All meals deleted successfully.',
    //     ]);
    // }
    public function deleteAllMeals(
        Request $request,
        Restaurant $restaurant
    ): JsonResponse {
        $this->requirePermission(
            $restaurant,
            'meals.delete'
        );

        $restaurant
            ->meals()
            ->update([
                'status' => MealStatus::INACTIVE,
            ]);

        return response()->json([
            'message' => 'All meals deleted successfully.',
        ]);
    }
    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    */

    /*
    |--------------------------------------------------------------------------
    | Tenant isolation
    |--------------------------------------------------------------------------
    */

    protected function ensureMealBelongsToRestaurant(
        Meal $meal,
        Restaurant $restaurant
    ): void {
        if (
            (int) $meal->restaurant_id !==
            (int) $restaurant->id
        ) {
            abort(404, 'Meal not found.');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Image lifecycle
    |--------------------------------------------------------------------------
    */

    protected function deleteMealImage(
        ?string $image,
        ?string $publicId
    ): void {

        /*
        |--------------------------------------------------------------------------
        | Preferred: stored Cloudinary public ID
        |--------------------------------------------------------------------------
        */

        if ($publicId) {
            try {

                Cloudinary::destroy($publicId);

            } catch (\Throwable $e) {

                Log::error(
                    'Cloudinary meal image deletion failed.',
                    [
                        'public_id' => $publicId,
                        'error' => $e->getMessage(),
                    ]
                );
            }

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Legacy Cloudinary image
        |--------------------------------------------------------------------------
        |
        | Old meals may have a Cloudinary URL but no public_id.
        |
        */

        if ($image && filter_var($image, FILTER_VALIDATE_URL)) {

            $legacyPublicId = $this->extractCloudinaryPublicId(
                $image
            );

            if ($legacyPublicId) {

                try {

                    Cloudinary::destroy(
                        $legacyPublicId
                    );

                } catch (\Throwable $e) {

                    Log::error(
                        'Legacy Cloudinary meal image deletion failed.',
                        [
                            'url' => $image,
                            'public_id' => $legacyPublicId,
                            'error' => $e->getMessage(),
                        ]
                    );
                }
            }

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Legacy local image
        |--------------------------------------------------------------------------
        */

        if (
            $image &&
            Storage::disk('public')->exists($image)
        ) {
            Storage::disk('public')->delete($image);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Extract Cloudinary public ID from legacy URL
    |--------------------------------------------------------------------------
    */

    protected function extractCloudinaryPublicId(
        string $url
    ): ?string {

        $parsed = parse_url($url);

        if (! isset($parsed['path'])) {
            return null;
        }

        $path = ltrim(
            $parsed['path'],
            '/'
        );

        if (
            preg_match(
                '/^(?:image|video|raw|auto)\/upload\/(?:v\d+\/)?(.+)$/',
                $path,
                $matches
            )
        ) {

            $publicId = $matches[1];

            return preg_replace(
                '/\.[^.]+$/',
                '',
                $publicId
            );
        }

        return null;
    }
}