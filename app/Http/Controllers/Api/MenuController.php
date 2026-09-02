<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\OrderRequest;
use App\Models\Meal;
use App\Models\MenuView;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Services\TranslationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MenuController extends Controller
{
    public function show(Request $request, string $slug, TranslationService $translator): JsonResponse
    {
        $language = $request->query('lang', 'en');

        $restaurant = Restaurant::where('slug', $slug)
            ->with(['categories', 'meals.translations'])
            ->firstOrFail();

        $meals = $restaurant->meals->filter(fn ($meal) => $meal->status->value === 'active');

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
            'categories' => $restaurant->categories->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
                'image_url' => $category->image ? url('storage/'.$category->image) : null,
            ]),
            'meals' => $meals,
            'tables' => $restaurant->tables()->orderBy('number')->get()->map(fn (RestaurantTable $table) => [
                'id' => $table->id,
                'restaurant_id' => $table->restaurant_id,
                'name' => $table->name,
                'number' => $table->number,
                'qr_token' => $table->qr_token,
                'status' => $table->status,
            ]),
        ]);
    }

    public function storeOrder(OrderRequest $request, string $slug): JsonResponse
    {
        $restaurant = Restaurant::where('slug', $slug)->firstOrFail();
        $items = collect($request->input('items', []));
        $table = $restaurant->tables()->where('qr_token', $request->input('table_token'))->firstOrFail();

        $order = DB::transaction(function () use ($restaurant, $items, $table, $request) {
            $order = Order::create([
                'restaurant_id' => $restaurant->id,
                'customer_name' => $request->customer_name,
                'phone' => $request->phone,
                'address' => $request->address,
                'status' => OrderStatus::PENDING,
                'total' => 0,
                'table_id' => $table->id,
                'table_token' => $table->qr_token,
            ]);

            $total = 0;

            foreach ($items as $item) {
                $meal = $restaurant->meals()->whereKey($item['meal_id'])->firstOrFail();
                $quantity = (int) ($item['quantity'] ?? 0);
                $lineTotal = $meal->price * $quantity;
                $total += $lineTotal;

                OrderItem::create([
                    'order_id' => $order->id,
                    'meal_id' => $meal->id,
                    'quantity' => $quantity,
                    'unit_price' => $meal->price,
                    'total_price' => $lineTotal,
                    'notes' => (string) ($item['notes'] ?? ''),
                ]);
            }

            $order->update(['total' => $total]);
            $table->update(['status' => 'reserved']);

            return $order->load('items');
        });

        return response()->json([
            'message' => 'Order created successfully',
            'order' => $order,
        ], 201);
    }

    public function recordView(Request $request, string $slug): JsonResponse
    {
        $restaurant = Restaurant::where('slug', $slug)->firstOrFail();
        $mealId = $request->input('meal_id');

        if ($mealId !== null && ! $restaurant->meals()->whereKey($mealId)->exists()) {
            abort(422, 'The selected meal is not available for this restaurant.');
        }

        MenuView::create([
            'restaurant_id' => $restaurant->id,
            'meal_id' => $mealId,
            'language' => $request->query('lang', 'en'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['message' => 'View recorded.']);
    }
}
