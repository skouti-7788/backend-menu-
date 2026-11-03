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
use App\Models\RestaurantAppearance;
use App\Models\RestaurantTable;
use App\Services\TranslationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Enums\MealStatus;
class MenuController extends Controller
{
    public function show(Request $request, string $slug, TranslationService $translator): JsonResponse
    {
        $validated = $request->validate([
            'lang' => ['nullable', 'string', 'in:en,fr,ar'],
        ]);

        $language = $validated['lang'] ?? 'en';
        $restaurant = Restaurant::where('slug', $slug)
            ->with([
                'categories' => fn ($query) => $query
                    ->where('status', MealStatus::ACTIVE)
                    ->with('translations'),
                'meals' => fn ($query) => $query
                    ->where('status', MealStatus::ACTIVE)
                    ->with('translations'),
                'translations',
            ])
            ->firstOrFail();

        // Generate and cache restaurant translations from the original content.
        $restaurantSource = [
            'name' => $restaurant->name,
            'description' => $restaurant->description ?? '',
        ];
        $restaurantHash = hash('sha256', json_encode($restaurantSource, JSON_UNESCAPED_UNICODE));
        $restaurantTranslation = $restaurant->translations->firstWhere('language', $language);

        if (! $restaurantTranslation || $restaurantTranslation->is_manual || $restaurantTranslation->source_hash !== $restaurantHash) {
            $translated = $translator->translateFields($restaurantSource, $language);
            $translationSucceeded = $translated !== $restaurantSource;

            if ($translationSucceeded) {
                $restaurantTranslation = $restaurant->translations()->updateOrCreate(
                    ['language' => $language],
                    [...$translated, 'is_manual' => false, 'source_hash' => $restaurantHash]
                );
            } else {
                $restaurantTranslation = new \App\Models\RestaurantTranslation([
                    'language' => $language,
                    ...$restaurantSource,
                ]);
            }
        }

        $mealItems = $restaurant->meals->map(function ($meal) use ($language, $translator) {
            $translation = $translator->translateMeal($meal, $language);

            return [
                'id' => $meal->id,
                'category_id' => $meal->category_id,
                'name' => $translation->name,
                'description' => $translation->description,
                'price' => $meal->price,
                'image_url' => $meal->image_url,
                'featured' => $meal->featured,
            ];
        });

        $categoryItems = $restaurant->categories->map(function ($category) use ($language, $translator) {
            $categorySource = [
                'name' => $category->name,
                'description' => $category->description ?? '',
            ];
            $categoryHash = hash('sha256', json_encode($categorySource, JSON_UNESCAPED_UNICODE));
            $translation = $category->translations->firstWhere('language', $language);

            if (! $translation || $translation->is_manual || $translation->source_hash !== $categoryHash) {
                $translated = $translator->translateFields($categorySource, $language);
                $translationSucceeded = $translated !== $categorySource;

                if ($translationSucceeded) {
                    $translation = $category->translations()->updateOrCreate(
                        ['language' => $language],
                        [...$translated, 'is_manual' => false, 'source_hash' => $categoryHash]
                    );
                } else {
                    $translation = new \App\Models\MenuCategoryTranslation([
                        'language' => $language,
                        ...$categorySource,
                    ]);
                }
            }

            return [
                'id' => $category->id,
                'name' => $translation->name,
                'description' => $translation->description,
                'image_url' => $category->image ? url('storage/'.$category->image) : null,
            ];
        });

        $appearance = $restaurant->appearance;

        return response()->json([
            'restaurant' => [
                'id' => $restaurant->id,
                'slug' => $restaurant->slug,
                'name' => $restaurantTranslation->name,
                'description' => $restaurantTranslation->description,
                'address' => $restaurant->address,
                'phone' => $restaurant->phone,
                'email' => $restaurant->email,
                'opening_hours' => $restaurant->opening_hours,
                'social_links' => $restaurant->social_links,
                'logo_url' => $restaurant->logo_url,
                'cover_image_url' => $restaurant->cover_image_url,
                'menu_url' => $restaurant->menu_url,
            ],
            'appearance' => [
                'id' => $appearance?->id,
                'restaurant_id' => $restaurant->id,
                'logo' => $appearance?->logo,
                'header_image' => $appearance?->header_image,
                'background_image' => $appearance?->background_image,
                'primary_color' => $appearance?->primary_color ?? '#D97706',
                'secondary_color' => $appearance?->secondary_color ?? '#92400E',
                'text_color' => $appearance?->text_color ?? '#1F2937',
                'background_color' => $appearance?->background_color ?? '#FFFFFF',
                'font_family' => $appearance?->font_family ?? 'Inter',
            ],
            'categories' => $categoryItems,
            'meals' => $mealItems,
            'tables' => app(\App\Services\PlanEnforcer::class)->hasFeature($restaurant, 'tables')
                ? $restaurant->tables()
                    ->where('status', '!=', 'inactive')
                    ->orderBy('number')
                    ->get()
                    ->map(fn (RestaurantTable $table) => [
                        'id' => $table->id,
                        'name' => $table->name,
                        'number' => $table->number,
                        'status' => $table->status,
                    ])
                : [],
        ]);
    }

    public function storeOrder(OrderRequest $request, string $slug): JsonResponse
    {
        $restaurant = Restaurant::where('slug', $slug)->firstOrFail();
        app(\App\Services\PlanEnforcer::class)->assertFeature($restaurant, 'orders');

        $items = collect($request->input('items', []));

        $table = $restaurant
            ->tables()
            ->where('qr_token', $request->input('table_token'))
            ->firstOrFail();

        $order = DB::transaction(function () use (
            $restaurant,
            $items,
            $table,
            $request
        ) {
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

            $subtotalCents = 0;

            foreach ($items as $item) {
                $meal = $restaurant
                    ->meals()
                    ->whereKey($item['meal_id'])
                    ->where('status', 'active')
                    ->firstOrFail();

                $quantity = (int) ($item['quantity'] ?? 0);

                $unitPriceCents = (int) round(
                    ((float) $meal->price) * 100
                );

                $lineTotalCents = $unitPriceCents * $quantity;

                $subtotalCents += $lineTotalCents;

                OrderItem::create([
                    'order_id' => $order->id,
                    'meal_id' => $meal->id,
                    'quantity' => $quantity,
                    'unit_price' => number_format(
                        $unitPriceCents / 100,
                        2,
                        '.',
                        ''
                    ),
                    'total_price' => number_format(
                        $lineTotalCents / 100,
                        2,
                        '.',
                        ''
                    ),
                    'notes' => (string) ($item['notes'] ?? ''),
                ]);
            }

            $taxCents = (int) round($subtotalCents * 0.09);

            $totalCents = $subtotalCents + $taxCents;

            $order->update([
                'total' => number_format(
                    $totalCents / 100,
                    2,
                    '.',
                    ''
                ),
            ]);

            $table->update([
                'status' => 'reserved',
            ]);

            return $order->load('items');
        });

        $subtotalCents = $order->items->sum(
            fn ($item) => (int) round(
                ((float) $item->total_price) * 100
            )
        );

        $taxCents = (int) round($subtotalCents * 0.09);

        return response()->json([
            'message' => __('messages.order_created'),
            'order' => [
                'id' => $order->id,
                'restaurant_id' => $order->restaurant_id,
                'customer_name' => $order->customer_name,
                'phone' => $order->phone,
                'address' => $order->address,
                'status' => $order->status->value,

                'subtotal' => number_format(
                    $subtotalCents / 100,
                    2,
                    '.',
                    ''
                ),

                'tax' => number_format(
                    $taxCents / 100,
                    2,
                    '.',
                    ''
                ),

                'total' => $order->total,

                'table_id' => $order->table_id,

                'items' => $order->items->map(fn ($item) => [
                    'meal_id' => $item->meal_id,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'total_price' => $item->total_price,
                    'notes' => $item->notes,
                ]),
            ],
        ], 201);
    }
        
    public function recordView(Request $request, string $slug): JsonResponse
    {
        $validated = $request->validate([
            'meal_id' => [
                'nullable',
                'integer',
            ],
            'lang' => [
                'nullable',
                'string',
                'in:en,fr,ar',
            ],
        ]);

        $restaurant = Restaurant::where('slug', $slug)->firstOrFail();

        $mealId = $validated['meal_id'] ?? null;

        if (
            $mealId !== null
            && ! $restaurant->meals()
                ->whereKey($mealId)
                ->where('status', 'active')
                ->exists()
        ) {
            return response()->json([
                'message' => __('messages.meal_unavailable'),
            ], 422);
        }

        MenuView::create([
            'restaurant_id' => $restaurant->id,
            'meal_id' => $mealId,
            'language' => $validated['lang'] ?? 'en',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => __('messages.view_recorded'),
        ]);
    }
}
