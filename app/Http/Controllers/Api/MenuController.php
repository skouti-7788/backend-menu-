<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MenuView;
use App\Models\Restaurant;
use App\Services\TranslationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Requests\Order\OrderRequest;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\OrderItem;
use App\Models\Meal;
use App\Enums\OrderStatus;

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
            'tables' =>  RestaurantTable::all(),
        ]);
    }
    


    public function storeOrder(
        OrderRequest $request,
        string $slug
    ): JsonResponse {

        // 1. Find restaurant
        $restaurant = Restaurant::where('slug', $slug)->firstOrFail();

        // 2. Get items
        $items = collect($request->input('items'));
        // 3. Create order
        $table = RestaurantTable::where('qr_token', $request->table_token)
        ->where('restaurant_id', $restaurant->id)
        ->firstOrFail();
        $order = Order::create([
            'restaurant_id' => $restaurant->id,
            'customer_name' => $request->customer_name,
            'phone' => $request->phone,
            'address' => $request->address,

            'status' => $request->status
                ? OrderStatus::from($request->status)
                : OrderStatus::PENDING,

            'total' => 0,
             'table_id' => $table->id,
             'table_token' => $table->qr_token,
         ]);

        // 4. Calculate total
        $total = 0;

        foreach ($items as $item) {

            $meal = Meal::findOrFail($item['meal_id']);

            $lineTotal =
                $meal->price * $item['quantity'];

            $total += $lineTotal;

            OrderItem::create([
                'order_id' => $order->id,
                'meal_id' => $meal->id,
                'quantity' => $item['quantity'],
                'unit_price' => $meal->price,
                'total_price' => $lineTotal,
                'notes' => $item['notes'] ?? null,
            ]);
        }

        // 5. Update order total
        $order->update([
            'total' => $total,
        ]);
        $table->update([
            'status' => 'reserved',
        ]);
        // 6. Return order
        return response()->json([
            'message' => 'Order created successfully',
            'order' => $order->load('items'),
        ], 201);
    }
 
    public function recordView(Request $request, string $slug): JsonResponse
    {
        $restaurant = Restaurant::where('slug', $slug)->firstOrFail();

        MenuView::create([
            'restaurant_id' => $restaurant->id,
            'meal_id' => $request->integer('meal_id'),
            'language' => $request->query('lang', 'en'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json(['message' => 'View recorded.']);
    }
}
