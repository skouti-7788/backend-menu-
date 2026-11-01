<?php

namespace App\Http\Controllers;

use App\Models\Restaurant;
use App\Support\SubscriptionPlans;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function resolveRestaurantForUser(Request $request): Restaurant
    {
        $user = $request->user();

        if (! $user) abort(401, 'Unauthenticated.');

        if ($user->isAdmin()) {
            $restaurantId = $request->input('restaurant_id');
            if ($restaurantId) return Restaurant::findOrFail($restaurantId);
            $restaurant = Restaurant::query()->latest()->first();
            if (! $restaurant) abort(404, 'No restaurant found.');
            return $restaurant;
        }

        if ($user->restaurant_id) {
            $restaurant = Restaurant::find($user->restaurant_id);
            if ($restaurant && ($restaurant->user_id === $user->id || $user->restaurant_id === $restaurant->id)) return $restaurant;
        }

        $restaurant = $user->restaurants()->latest()->first();
        if (! $restaurant) abort(404, 'Restaurant not found for this user.');
        return $restaurant;
    }

    protected function authorizeOwner(Restaurant $restaurant): void
    {
        $user = auth()->user();
        if (! $user) abort(401, 'Unauthenticated.');
        if ($user->isAdmin()) return;
        if ($restaurant->user_id === $user->id && ($user->isOwner() || $user->isRestaurantManager())) return;
        abort(403, 'You are not authorized to manage this restaurant.');
    }

    protected function authorizeOwnerOrStaff(Restaurant $restaurant): void
    {
        $user = auth()->user();
        if (! $user) abort(401, 'Unauthenticated.');
        if ($user->isAdmin()) return;
        if ($restaurant->user_id === $user->id && ($user->isOwner() || $user->isRestaurantManager())) return;
        if ($user->restaurant_id && (int) $user->restaurant_id === (int) $restaurant->id && $user->isStaff()) return;
        abort(403, 'You are not authorized to manage this restaurant.');
    }

    protected function requirePlanFeature(Restaurant $restaurant, string $feature): void
    {
        $user = auth()->user();
        if ($user?->isAdmin()) return;

        if (! SubscriptionPlans::allows($restaurant->plan, $feature)) {
            abort(response()->json([
                'message' => 'This feature is not included in your current plan.',
                'code' => 'PLAN_UPGRADE_REQUIRED',
                'current_plan' => $restaurant->plan ?: 'starter',
                'required_feature' => $feature,
                'plans' => SubscriptionPlans::DEFINITIONS,
            ], 403));
        }
    }

    protected function requirePermission(Restaurant $restaurant, string $permission): void
    {
        $user = auth()->user();
        if (! $user) abort(401, 'Unauthenticated.');
        if ($user->isAdmin()) return;

        $featureByPermission = [
            'orders.view' => 'orders', 'orders.add' => 'orders', 'orders.update' => 'orders', 'orders.delete' => 'orders',
            'tables.view' => 'tables', 'tables.add' => 'tables', 'tables.update' => 'tables', 'tables.delete' => 'tables',
            'staff.view' => 'staff', 'staff.add' => 'staff', 'staff.update' => 'staff', 'staff.delete' => 'staff',
            'appearance.view' => 'advanced_appearance', 'appearance.update' => 'advanced_appearance', 'appearance.delete' => 'advanced_appearance',
        ];

        if (isset($featureByPermission[$permission])) {
            $this->requirePlanFeature($restaurant, $featureByPermission[$permission]);
        }

        if ($restaurant->user_id === $user->id && ($user->isOwner() || $user->isRestaurantManager())) return;

        $belongsToRestaurant = $user->restaurant_id && (int) $user->restaurant_id === (int) $restaurant->id;
        if ($belongsToRestaurant && $user->isStaff()) {
            if ($user->hasPermission($permission)) return;
            abort(403, 'You do not have permission to perform this action.');
        }

        abort(403, 'You are not authorized to manage this restaurant.');
    }
}
