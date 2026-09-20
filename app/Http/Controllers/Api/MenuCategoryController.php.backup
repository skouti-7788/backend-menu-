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
            $this->deleteFile(
                $menuCategory->image
            );

            $data['image'] =
                $request
                    ->file('image')
                    ->store(
                        'categories',
                        'public'
                    );
        }

        $menuCategory->update($data);

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
     * Check restaurant access + permission.
     *
     * Rules:
     *
     * Admin
     *   -> full access
     *
     * Owner / Restaurant Manager
     *   -> access to restaurants they own
     *
     * Staff
     *   -> access only to their restaurant
     *   -> permission is required
     */
    protected function requirePermission(
        Restaurant $restaurant,
        string $permission
    ): void {
        $user = auth()->user();

        if (! $user) {
            abort(
                401,
                'Unauthenticated.'
            );
        }

        /*
         * ADMIN
         */
        if ($user->isAdmin()) {
            return;
        }

        /*
         * OWNER / MANAGER
         *
         * The restaurant belongs to this user.
         */
        if (
            (int) $restaurant->user_id ===
            (int) $user->id
        ) {
            return;
        }

        /*
         * STAFF
         *
         * Staff must belong to this restaurant.
         */
        if (
            $user->isStaff() &&
            (int) $user->restaurant_id ===
            (int) $restaurant->id
        ) {
            if (
                $user->hasPermission(
                    $permission
                )
            ) {
                return;
            }

            abort(
                403,
                'You do not have permission to perform this action.'
            );
        }

        /*
         * User does not belong
         * to this restaurant.
         */
        abort(
            403,
            'You are not authorized to access this restaurant.'
        );
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