<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\OrderRequest;
use App\Http\Resources\OrderResource;
use App\Enums\OrderStatus;
use App\Models\Meal;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index(Request $request, Restaurant $restaurant)
    {
        $this->authorizeRestaurant($restaurant);

        return OrderResource::collection($restaurant->orders()->with('items')->latest()->get());
    }

    public function store(OrderRequest $request, Restaurant $restaurant): OrderResource
    {
        $this->authorizeRestaurant($restaurant);

        $items = collect($request->input('items', []));

        $order = DB::transaction(function () use ($restaurant, $request, $items) {
            $order = Order::create([
                'restaurant_id' => $restaurant->id,
                'customer_name' => $request->customer_name,
                'phone' => $request->phone,
                'address' => $request->address,
                'status' => $request->status ? OrderStatus::from($request->status) : OrderStatus::PENDING,
                'total' => 0,
            ]);

            $subtotal = 0;

            foreach ($items as $item) {
                $meal = $restaurant->meals()->whereKey($item['meal_id'])->where('status', 'active')->firstOrFail();
                $quantity = (int) ($item['quantity'] ?? 0);
                $lineTotal = (float) $meal->price * $quantity;
                $subtotal += $lineTotal;

                OrderItem::create([
                    'order_id' => $order->id,
                    'meal_id' => $meal->id,
                    'quantity' => $quantity,
                    'unit_price' => $meal->price,
                    'total_price' => $lineTotal,
                    'notes' => (string) ($item['notes'] ?? ''),
                ]);
            }

            $tax = round($subtotal * 0.09, 2);
            $total = round($subtotal + $tax, 2);

            $order->update(['total' => $total]);

            return $order->load('items');
        });

        return new OrderResource($order);
    }
    // public function store(
    //         OrderRequest $request,
    //         Restaurant $restaurant
    //     ): OrderResource {

    //         $this->authorizeRestaurant($restaurant);

    //         $order = DB::transaction(function () use ($request, $restaurant) {

    //             $items = collect($request->input('items'));

    //             $order = Order::create([
    //                 'restaurant_id' => $restaurant->id,
    //                 'customer_name' => $request->customer_name,
    //                 'phone' => $request->phone,
    //                 'address' => $request->address,
    //                 'status' => $request->status
    //                     ? OrderStatus::from($request->status)
    //                     : OrderStatus::PENDING,
    //                 'total' => 0,
    //                 'table_number' => $request->table_number,
    //             ]);

    //             $total = 0;

    //             foreach ($items as $item) {

    //                 $meal = Meal::findOrFail($item['meal_id']);

    //                 $lineTotal =
    //                     $meal->price * $item['quantity'];

    //                 $total += $lineTotal;

    //                 OrderItem::create([
    //                     'order_id' => $order->id,
    //                     'meal_id' => $meal->id,
    //                     'quantity' => $item['quantity'],
    //                     'unit_price' => $meal->price,
    //                     'total_price' => $lineTotal,
    //                     'notes' => $item['notes'] ?? null,
    //                 ]);
    //             }

    //             $order->update([
    //                 'total' => $total,
    //             ]);

    //             return $order->load('items');
    //         });

    //         return new OrderResource($order);
    //     }
    public function show(Request $request, Order $order): OrderResource
    {
        $this->authorizeRestaurant($order->restaurant);

        return new OrderResource($order->load('items'));
    }

    public function update(Request $request, Order $order): OrderResource
    {
        $this->authorizeRestaurant($order->restaurant);

        $validated = $request->validate([
            'customer_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:1024'],
            'status' => ['nullable', 'in:pending,preparing,ready,completed,cancelled'],
        ]);

        if (isset($validated['status'])) {
            $this->syncTableStatusAfterOrderStatusChange($order, $validated['status']);
        }

        $order->update($validated);

        return new OrderResource($order->load('items'));
    }

    public function destroy(Order $order): JsonResponse
    {
        $this->authorizeRestaurant($order->restaurant);

        $order->items()->delete();
        $order->delete();

        $this->syncTableStatusForOrder($order);

        return response()->json(['message' => 'Order deleted successfully.']);
    }

    public function updateStatus(Request $request, Order $order): OrderResource
    {
        $this->authorizeRestaurant($order->restaurant);

        $validated = $request->validate([
            'status' => ['required', 'in:pending,preparing,ready,completed,cancelled'],
        ]);

        $this->syncTableStatusAfterOrderStatusChange($order, $validated['status']);
        $order->update($validated);

        return new OrderResource($order->load('items'));
    }

    protected function syncTableStatusAfterOrderStatusChange(Order $order, string $newStatus): void
    {
        if ($order->table_id === null) {
            return;
        }

        if (! in_array($newStatus, ['completed', 'cancelled'], true)) {
            if ($order->table && $order->table->status !== 'reserved') {
                $order->table->update(['status' => 'reserved']);
            }

            return;
        }

        $this->syncTableStatusForOrder($order);
    }

    protected function syncTableStatusForOrder(Order $order): void
    {
        if ($order->table_id === null || $order->table === null) {
            return;
        }

        $hasActiveOrder = $order->table->orders()
            ->where('id', '!=', $order->id)
            ->whereIn('status', ['pending', 'preparing', 'ready'])
            ->exists();

        if (! $hasActiveOrder) {
            $order->table->update(['status' => 'available']);
        }
    }

    protected function authorizeRestaurant(Restaurant $restaurant): void
    {
        $user = auth()->user();

        if ($user->role !== 'admin' && $restaurant->user_id !== $user->id) {
            abort(403, 'You are not authorized to manage this restaurant.');
        }
    }
}
