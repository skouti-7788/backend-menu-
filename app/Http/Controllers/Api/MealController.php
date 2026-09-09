<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Meal\MealRequest;
use App\Http\Resources\MealResource;
use App\Models\Meal;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MealController extends Controller
{
    /**
     * =====================================================
     * LIST MEALS
     * =====================================================
     *
     * Owner:
     *   -> accès automatique
     *
     * Staff:
     *   -> meals.view
     */
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

    /**
     * =====================================================
     * CREATE MEAL
     * =====================================================
     *
     * Permission:
     *   meals.add
     */
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

        /**
         * Always force restaurant_id
         * from route restaurant.
         */
        $data['restaurant_id'] =
            $restaurant->id;

        /**
         * Upload image
         */
        if ($request->hasFile('image')) {
            $data['image'] =
                $request
                    ->file('image')
                    ->store(
                        'meals',
                        'public'
                    );
        }

        $meal = Meal::create($data);

        /**
         * Load translations
         * for API resource.
         */
        $meal->load('translations');

        return new MealResource($meal);
    }

    /**
     * =====================================================
     * SHOW MEAL
     * =====================================================
     *
     * Permission:
     *   meals.view
     *
     * Important:
     * The meal MUST belong to the restaurant
     * from the URL.
     */
    public function show(
        Request $request,
        Restaurant $restaurant,
        Meal $meal
    ): MealResource {
        $this->requirePermission(
            $restaurant,
            'meals.view'
        );

        /**
         * Security:
         * prevent accessing a meal
         * belonging to another restaurant.
         */
        $this->ensureMealBelongsToRestaurant(
            $meal,
            $restaurant
        );

        $meal->load('translations');

        return new MealResource($meal);
    }

    /**
     * =====================================================
     * UPDATE MEAL
     * =====================================================
     *
     * Permission:
     *   meals.update
     */
    public function update(
        MealRequest $request,
        Restaurant $restaurant,
        Meal $meal
    ): MealResource {
        $this->requirePermission(
            $restaurant,
            'meals.update'
        );

        /**
         * Security:
         * ensure the meal belongs
         * to the current restaurant.
         */
        $this->ensureMealBelongsToRestaurant(
            $meal,
            $restaurant
        );

        $data = $request
            ->safe()
            ->except(['image']);

        /**
         * Replace image
         */
        if ($request->hasFile('image')) {

            /**
             * Delete old image
             */
            $this->deleteFile(
                $meal->image
            );

            /**
             * Store new image
             */
            $data['image'] =
                $request
                    ->file('image')
                    ->store(
                        'meals',
                        'public'
                    );
        }

        $meal->update($data);

        $meal->refresh();

        $meal->load('translations');

        return new MealResource($meal);
    }

    /**
     * =====================================================
     * DELETE MEAL
     * =====================================================
     *
     * Permission:
     *   meals.delete
     */
    public function destroy(
        Request $request,
        Restaurant $restaurant,
        Meal $meal
    ): JsonResponse {
        $this->requirePermission(
            $restaurant,
            'meals.delete'
        );

        /**
         * Security:
         * ensure this meal belongs
         * to this restaurant.
         */
        $this->ensureMealBelongsToRestaurant(
            $meal,
            $restaurant
        );

        /**
         * Delete image
         */
        $this->deleteFile(
            $meal->image
        );

        /**
         * Delete meal
         */
        $meal->delete();

        return response()->json([
            'message' =>
                'Meal deleted successfully.'
        ]);
    }

    /**
     * =====================================================
     * REQUIRE PERMISSION
     * =====================================================
     *
     * Owner:
     *   -> all permissions
     *
     * Admin:
     *   -> all permissions
     *
     * Staff:
     *   -> only assigned permissions
     *
     * Restaurant manager:
     *   -> can manage his restaurant
     */
    protected function requirePermission(
        Restaurant $restaurant,
        string $permission
    ): void {
        $user = auth()->user();

        /**
         * No authenticated user
         */
        if (! $user) {
            abort(
                401,
                'Unauthenticated.'
            );
        }

        /**
         * Admin has full access.
         */
        if ($user->isAdmin()) {
            return;
        }

        /**
         * IMPORTANT:
         *
         * The restaurant must belong
         * to the authenticated user.
         *
         * Owner:
         * restaurant.user_id = user.id
         *
         * Staff:
         * restaurant_id = user.restaurant_id
         */
        if (
            $user->role === 'owner'
        ) {
            if (
                (int) $restaurant->user_id !==
                (int) $user->id
            ) {
                abort(
                    403,
                    'You are not authorized to manage this restaurant.'
                );
            }

            return;
        }

        /**
         * Staff access
         */
        if (
            $user->role === 'staff'
        ) {
            if (
                (int) $user->restaurant_id !==
                (int) $restaurant->id
            ) {
                abort(
                    403,
                    'You are not authorized to manage this restaurant.'
                );
            }

            /**
             * Check assigned permission.
             */
            if (
                ! $user->hasPermission(
                    $permission
                )
            ) {
                abort(
                    403,
                    'You do not have permission to perform this action.'
                );
            }

            return;
        }

        /**
         * Restaurant manager
         *
         * In case you still use this role.
         */
        if (
            $user->role ===
            'restaurant_manager'
        ) {
            if (
                (int) $restaurant->user_id !==
                (int) $user->id
            ) {
                abort(
                    403,
                    'You are not authorized to manage this restaurant.'
                );
            }

            return;
        }

        /**
         * Any other role
         * is forbidden.
         */
        abort(
            403,
            'You are not authorized to manage this restaurant.'
        );
    }

    /**
     * =====================================================
     * ENSURE MEAL BELONGS TO RESTAURANT
     * =====================================================
     */
    protected function ensureMealBelongsToRestaurant(
        Meal $meal,
        Restaurant $restaurant
    ): void {
        if (
            (int) $meal->restaurant_id !==
            (int) $restaurant->id
        ) {
            abort(
                404,
                'Meal not found.'
            );
        }
    }

    /**
     * =====================================================
     * DELETE FILE
     * =====================================================
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
 
