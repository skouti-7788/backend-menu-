<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;

class AnalyticsController extends Controller
{
    public function restaurantOverview(Restaurant $restaurant): JsonResponse
    {
        $this->authorizeRestaurant($restaurant);

        $ordersCount = $restaurant->orders()->count();
        $viewsCount = $restaurant->views()->count();
        $featuredMeals = $restaurant->meals()->where('featured', true)->count();

        return response()->json([
            'restaurant_id' => $restaurant->id,
            'orders_count' => $ordersCount,
            'menu_views_count' => $viewsCount,
            'featured_meals' => $featuredMeals,
        ]);
    }

    public function popularMeals(Restaurant $restaurant): JsonResponse
    {
        $this->authorizeRestaurant($restaurant);

        $meals = $restaurant->meals()
            ->select('meals.*')
            ->withCount(['orderItems as total_ordered' => fn ($query) => $query->selectRaw('coalesce(sum(quantity), 0)')])
            ->orderByDesc('total_ordered')
            ->limit(10)
            ->get();

        return response()->json(['popular_meals' => $meals]);
    }

    public function menuViews(Restaurant $restaurant): JsonResponse
    {
        $this->authorizeRestaurant($restaurant);

        $viewsPerLanguage = $restaurant->views()
            ->select('language')
            ->selectRaw('count(*) as total')
            ->groupBy('language')
            ->get();

        return response()->json(['menu_views' => $viewsPerLanguage]);
    }

    protected function authorizeRestaurant(Restaurant $restaurant): void
    {
        $user = auth()->user();

        if ($user->role !== 'admin' && $restaurant->user_id !== $user->id) {
            abort(403, 'You are not authorized to view analytics for this restaurant.');
        }
    }
}
