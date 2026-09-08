<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Restaurant;

abstract class Controller
{
    protected function resolveRestaurantForUser(Request $request): Restaurant
    {
        $user = $request->user();

        // Prefer explicit restaurant_id on user (newer flow)
        if ($user && isset($user->restaurant_id) && $user->restaurant_id) {
            return Restaurant::findOrFail($user->restaurant_id);
        }

        // Fallback to existing relationship (older flow)
        $restaurant = $user?->restaurants()->first();

        if (! $restaurant) {
            abort(404, 'Restaurant not found for this user.');
        }

        return $restaurant;
    }

    protected function authorizeOwner(Restaurant $restaurant): void
    {
        $user = auth()->user();

        if (! $user || $restaurant->user_id !== $user->id) {
            abort(403, 'You are not authorized to manage this restaurant.');
        }
    }

    protected function authorizeOwnerOrStaff(Restaurant $restaurant): void
    {
        $user = auth()->user();

        if (! $user) {
            abort(403, 'You are not authorized to manage this restaurant.');
        }

        // Owner of restaurant (restaurant.user_id)
        if ($restaurant->user_id === $user->id) {
            return;
        }

        // Staff that belongs to this restaurant (via users.restaurant_id)
        if (($user->role === 'staff' || $user->role === 'owner') && isset($user->restaurant_id) && $user->restaurant_id == $restaurant->id) {
            return;
        }

        abort(403, 'You are not authorized to manage this restaurant.');
    }

    /**
     * Require a specific permission for the authenticated user for a restaurant.
     * Owner of the restaurant is automatically allowed.
     */
    protected function requirePermission(Restaurant $restaurant, string $permission): void
    {
        $user = auth()->user();

        if (! $user) {
            abort(403, 'Unauthorized.');
        }

        // Owner of this restaurant
        if ($restaurant->user_id === $user->id) {
            return;
        }

        // Staff must belong to restaurant and have explicit permission
        if ($user->role === 'staff' && isset($user->restaurant_id) && $user->restaurant_id == $restaurant->id) {
            if ($user->hasPermission($permission)) {
                return;
            }
            abort(403, 'Forbidden.');
        }

        abort(403, 'You are not authorized to manage this restaurant.');
    }
}
