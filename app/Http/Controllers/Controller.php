<?php

namespace App\Http\Controllers;

use App\Models\Restaurant;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Resolve the restaurant associated with the authenticated user.
     *
     * Used by controllers that don't receive Restaurant
     * directly from the route.
     */
    protected function resolveRestaurantForUser(
        Request $request
    ): Restaurant {
        $user = $request->user();

        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        /*
        |--------------------------------------------------------------------------
        | Admin
        |--------------------------------------------------------------------------
        |
        | Admin is not restricted to a single restaurant.
        | If restaurant_id is explicitly provided, use it.
        |
        */

        if ($user->isAdmin()) {
            $restaurantId = $request->input('restaurant_id');

            if ($restaurantId) {
                return Restaurant::findOrFail($restaurantId);
            }

            /*
            | If admin has no explicit restaurant_id,
            | fallback to the first restaurant.
            */
            $restaurant = Restaurant::query()
                ->latest()
                ->first();

            if (! $restaurant) {
                abort(404, 'No restaurant found.');
            }

            return $restaurant;
        }

        /*
        |--------------------------------------------------------------------------
        | Current restaurant assigned to the user.
        |--------------------------------------------------------------------------
        */

        if ($user->restaurant_id) {
            $restaurant = Restaurant::find(
                $user->restaurant_id
            );

            if ($restaurant) {
                /*
                | Make sure the user is actually related
                | to this restaurant.
                */
                if (
                    $restaurant->user_id === $user->id
                    || $user->restaurant_id === $restaurant->id
                ) {
                    return $restaurant;
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Backward compatibility:
        | Owner relationship.
        |--------------------------------------------------------------------------
        */

        $restaurant = $user
            ->restaurants()
            ->latest()
            ->first();

        if (! $restaurant) {
            abort(
                404,
                'Restaurant not found for this user.'
            );
        }

        return $restaurant;
    }

    /**
     * Require the authenticated user to be the restaurant owner.
     */
    protected function authorizeOwner(
        Restaurant $restaurant
    ): void {
        $user = auth()->user();

        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        /*
        | Admin can manage all restaurants.
        */
        if ($user->isAdmin()) {
            return;
        }

        /*
        | Only the real owner can continue.
        */
        if (
            $restaurant->user_id === $user->id
            && $user->isOwner()
        ) {
            return;
        }

        abort(
            403,
            'You are not authorized to manage this restaurant.'
        );
    }

    /**
     * Authorize owner or staff access to a restaurant.
     *
     * This method checks only restaurant membership.
     * Permissions should be checked separately when required.
     */
    protected function authorizeOwnerOrStaff(
        Restaurant $restaurant
    ): void {
        $user = auth()->user();

        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        /*
        | Admin can access every restaurant.
        */
        if ($user->isAdmin()) {
            return;
        }

        /*
        | Restaurant owner.
        */
        if (
            $restaurant->user_id === $user->id
            && $user->isOwner()
        ) {
            return;
        }

        /*
        | Staff / restaurant manager assigned to this restaurant.
        */
        if (
            $user->restaurant_id
            && (int) $user->restaurant_id === (int) $restaurant->id
            && in_array(
                $user->role,
                ['staff', 'restaurant_manager'],
                true
            )
        ) {
            return;
        }

        abort(
            403,
            'You are not authorized to manage this restaurant.'
        );
    }

    /**
     * Require a specific permission for a restaurant.
     *
     * Rules:
     *
     * - Admin              → always allowed
     * - Restaurant owner   → always allowed
     * - Staff               → must belong to restaurant + permission
     * - Restaurant manager  → must belong to restaurant + permission
     * - Everyone else       → forbidden
     */
    protected function requirePermission(
        Restaurant $restaurant,
        string $permission
    ): void {
        $user = auth()->user();

        if (! $user) {
            abort(401, 'Unauthenticated.');
        }

        /*
        |--------------------------------------------------------------------------
        | ADMIN
        |--------------------------------------------------------------------------
        */

        if ($user->isAdmin()) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | OWNER
        |--------------------------------------------------------------------------
        |
        | Owner of this specific restaurant has full access.
        |
        */

        if (
            $restaurant->user_id === $user->id
            && $user->isOwner()
        ) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | STAFF / RESTAURANT MANAGER
        |--------------------------------------------------------------------------
        |
        | They must belong to this restaurant.
        |
        */

        $belongsToRestaurant =
            $user->restaurant_id
            && (int) $user->restaurant_id === (int) $restaurant->id;

        if (
            $belongsToRestaurant
            && in_array(
                $user->role,
                ['staff', 'restaurant_manager'],
                true
            )
        ) {
            /*
            | Explicit permission check.
            */
            if ($user->hasPermission($permission)) {
                return;
            }

            abort(
                403,
                'You do not have permission to perform this action.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | User doesn't belong to this restaurant.
        |--------------------------------------------------------------------------
        */

        abort(
            403,
            'You are not authorized to manage this restaurant.'
        );
    }
}
 
