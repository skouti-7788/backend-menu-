<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MenuCategory\MenuCategoryRequest;
use App\Http\Resources\MenuCategoryResource;
use App\Models\MenuCategory;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MenuCategoryController extends Controller
{
    /**
     * List categories of a restaurant.
     */
    public function index(
        Request $request,
        Restaurant $restaurant
    ) {
        $this->requirePermission(
            $restaurant,
            'categories.view'
        );

        return MenuCategoryResource::collection(
            $restaurant
                ->categories()
                ->latest()
                ->get()
        );
    }

    /**
     * Create category.
     */
    public function store(
        MenuCategoryRequest $request,
        Restaurant $restaurant
    ): MenuCategoryResource {
        $this->requirePermission(
            $restaurant,
            'categories.add'
        );

        if (($restaurant->plan ?: 'starter') === 'starter' && $restaurant->categories()->count() >= 5) {
            abort(response()->json([
                'message' => 'Starter plan allows up to 5 categories. Upgrade to Pro for unlimited categories.',
                'code' => 'PLAN_LIMIT_REACHED',
                'limit' => 5,
                'resource' => 'categories',
                'current_plan' => 'starter',
            ], 403));
        }

        $data = $request
            ->safe()
            ->except(['image']);

        $data['restaurant_id'] =
            $restaurant->id;

        $data['description'] =
            $request->description;

        if ($request->hasFile('image')) {
            $data['image'] = $request
                ->file('image')
                ->store(
                    'categories',
                    'public'
                );
        }

        $category =
            MenuCategory::create($data);

        return new MenuCategoryResource(
            $category->refresh()
        );
    }

    /**
     * Show category.
     */
    public function show(
        string $restaurant,
        string $category
    ): MenuCategoryResource {
        $restaurantModel =
            Restaurant::findOrFail(
                $restaurant
            );

        $this->requirePermission(
            $restaurantModel,
            'categories.view'
        );

        $menuCategory =
            MenuCategory::where(
                'id',
                $category
            )
            ->where(
                'restaurant_id',
                $restaurantModel->id
            )
            ->firstOrFail();

        return new MenuCategoryResource(
            $menuCategory
        );
    }

    /**
     * Update category.
     */
    public function update(
        MenuCategoryRequest $request,
        string $restaurant,
        string $category
    ): MenuCategoryResource {
        $restaurantModel =
            Restaurant::findOrFail(
                $restaurant
            );

        $this->requirePermission(
            $restaurantModel,
            'categories.update'
        );

        $menuCategory =
            MenuCategory::where(
                'id',
                $category
            )
            ->where(
                'restaurant_id',
                $restaurantModel->id
            )
            ->firstOrFail();

        $data = $request
            ->safe()
            ->except(['image']);

        $data['description'] =
            $request->description;

        if ($request->hasFile('image')) {
        $oldImage = $menuCategory->image;

        try {
            /*
            |--------------------------------------------------------------------------
            | Store new image first
            |--------------------------------------------------------------------------
            */
            $newImage = $request
                ->file('image')
                ->store(
                    'categories',
                    'public'
                );

            if (! $newImage) {
                throw new \RuntimeException(
                    'Category image could not be stored.'
                );
            }

            $data['image'] = $newImage;

            /*
            |--------------------------------------------------------------------------
            | Update DB first
            |--------------------------------------------------------------------------
            */
            $menuCategory->update($data);

            /*
            |--------------------------------------------------------------------------
            | DB update succeeded → delete old image
            |--------------------------------------------------------------------------
            */
            if (
                $oldImage &&
                $oldImage !== $newImage
            ) {
                $this->deleteFile($oldImage);
            }

        } catch (\Throwable $e) {

            /*
            |--------------------------------------------------------------------------
            | If DB update failed, clean up the new file.
            |--------------------------------------------------------------------------
            */
            if (
                isset($newImage) &&
                $newImage &&
                Storage::disk('public')->exists($newImage)
            ) {
                Storage::disk('public')->delete($newImage);
            }

            throw $e;
        }
    } else {
        $menuCategory->update($data);
    }

 
        return new MenuCategoryResource(
            $menuCategory->refresh()
        );
    }

    /**
     * Delete category.
     */
    public function destroy(
        string $restaurant,
        string $category
    ): JsonResponse {
        $restaurantModel =
            Restaurant::findOrFail(
                $restaurant
            );

        $this->requirePermission(
            $restaurantModel,
            'categories.delete'
        );

        $menuCategory =
            MenuCategory::where(
                'id',
                $category
            )
            ->where(
                'restaurant_id',
                $restaurantModel->id
            )
            ->firstOrFail();

        $this->deleteFile(
            $menuCategory->image
        );

        $menuCategory->delete();

        return response()->json([
            'message' =>
                'Menu category removed successfully.'
        ]);
    }

    /**
     * Delete stored image.
     */
    protected function deleteFile(
        ?string $path
    ): void {
        if (
            $path &&
            Storage::disk('public')->exists($path)
        ) {
            Storage::disk('public')->delete(
                $path
            );
        }
    }
}