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
            ->select([
                'meals.id',
                'meals.restaurant_id',
                'meals.category_id',
                'meals.name',
                'meals.description',
                'meals.price',
                'meals.image',
                'meals.status',
                'meals.featured',
                'meals.created_at',
                'meals.updated_at',
            ])
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
            'popular_meals' => $meals->map(fn ($meal) => [
                'id' => $meal->id,
                'restaurant_id' => $meal->restaurant_id,
                'category_id' => $meal->category_id,
                'name' => $meal->name,
                'description' => $meal->description,
                'price' => $meal->price,
                'image_url' => $meal->image_url,
                'status' => $meal->status->value,
                'featured' => $meal->featured,
                'total_ordered' => (int) $meal->total_ordered,
                'created_at' => $meal->created_at,
                'updated_at' => $meal->updated_at,
            ]),
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
 