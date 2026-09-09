<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;

class AnalyticsController extends Controller
{
    /**
     * Get restaurant analytics overview.
     */
    public function restaurantOverview(
        Restaurant $restaurant
    ): JsonResponse {
        $this->requirePermission(
            $restaurant,
            'dashboard.view'
        );

        $ordersCount = $restaurant
            ->orders()
            ->count();

        $viewsCount = $restaurant
            ->views()
            ->count();

        $featuredMeals = $restaurant
            ->meals()
            ->where('featured', true)
            ->count();

        return response()->json([
            'restaurant_id' => $restaurant->id,
            'orders_count' => $ordersCount,
            'menu_views_count' => $viewsCount,
            'featured_meals' => $featuredMeals,
        ]);
    }

    /**
     * Get the most popular meals.
     */
    public function popularMeals(
        Restaurant $restaurant
    ): JsonResponse {
        $this->requirePermission(
            $restaurant,
            'dashboard.view'
        );

        $meals = $restaurant
            ->meals()
            ->select('meals.*')
            ->withCount([
                'orderItems as total_ordered' => function ($query) {
                    $query->selectRaw(
                        'COALESCE(SUM(quantity), 0)'
                    );
                },
            ])
            ->orderByDesc('total_ordered')
            ->limit(10)
            ->get();

        return response()->json([
            'popular_meals' => $meals,
        ]);
    }

    /**
     * Get menu views grouped by language.
     */
    public function menuViews(
        Restaurant $restaurant
    ): JsonResponse {
        $this->requirePermission(
            $restaurant,
            'dashboard.view'
        );

        $viewsPerLanguage = $restaurant
            ->views()
            ->select('language')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('language')
            ->get();

        return response()->json([
            'menu_views' => $viewsPerLanguage,
        ]);
    }
}
 