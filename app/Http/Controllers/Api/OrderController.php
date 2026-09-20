<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Order\OrderRequest;
use App\Http\Resources\OrderResource;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    /**
     * =====================================================
     * LIST ORDERS
     * =====================================================
     */
    public function index(
        Request $request,
        Restaurant $restaurant
    ) {
        $this->requirePermission(
            $restaurant,
            'orders.view'
        );

        $orders = $restaurant
            ->orders()
            ->with([
                'items',
                'table',
            ])
            ->latest()
            ->get();

        return OrderResource::collection(
            $orders
        );
    }

    /**
     * =====================================================
     * CREATE ORDER
     * =====================================================
     *
     * Mainly used by authenticated dashboard users.
     *
     * Public menu orders should continue using
     * MenuController::storeOrder().
     */
    public function store(
        OrderRequest $request,
        Restaurant $restaurant
    ): OrderResource {
        $this->requirePermission(
            $restaurant,
            'orders.add'
        );

        $items = collect($request->input('items', []));

        $order = DB::transaction(function () use ($restaurant, $request, $items) {
            $order = Order::create([
                'restaurant_id' => $restaurant->id,
                'customer_name' => $request->customer_name,
                'phone' => $request->phone,
                'address' => $request->address,
                'status' => $request->status
                    ? OrderStatus::from($request->status)
                    : OrderStatus::PENDING,
                'total' => 0,
                'table_id' => $request->table_id,
            ]);

            $subtotalCents = 0;

            foreach ($items as $item) {
                $meal = $restaurant
                    ->meals()
                    ->whereKey($item['meal_id'])
                    ->where('status', 'active')
                    ->firstOrFail();

                $quantity = (int) ($item['quantity'] ?? 0);

                // Convert price to cents and calculate using integers.
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

            // 9% tax, calculated in cents.
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

            // A dashboard order reserves its selected table.
            if ($order->table_id !== null) {
                $table = $order->table;

                if ($table) {
                    $table->update([
                        'status' => 'reserved',
                    ]);
                }
            }

            return $order->load(['items', 'table']);
        });

        return new OrderResource(
            $order
        );
    }

    /**
     * =====================================================
     * SHOW ORDER
     * =====================================================
     */
    public function show(
        Request $request,
        Order $order
    ): OrderResource {

        /**
         * Permission is checked against
         * the restaurant that owns the order.
         */
        $this->requirePermission(
            $order->restaurant,
            'orders.view'
        );

        return new OrderResource(
            $order->load([
                'items',
                'table',
            ])
        );
    }

    /**
     * =====================================================
     * UPDATE ORDER
     * =====================================================
     */
    public function update(
        Request $request,
        Order $order
    ): OrderResource {

        $this->requirePermission(
            $order->restaurant,
            'orders.update'
        );

        $validated =
            $request->validate([
                'customer_name' => [
                    'nullable',
                    'string',
                    'max:255',
                ],

                'phone' => [
                    'nullable',
                    'string',
                    'max:32',
                ],

                'address' => [
                    'nullable',
                    'string',
                    'max:1024',
                ],

                'status' => [
                    'nullable',
                    'in:pending,preparing,ready,completed,cancelled',
                ],
            ]);

        /**
         * Update table status BEFORE
         * changing order status.
         */
        if (
            isset(
                $validated['status']
            )
        ) {
            $this->syncTableStatusAfterOrderStatusChange(
                $order,
                $validated['status']
            );
        }

        $order->update(
            $validated
        );

        return new OrderResource(
            $order->fresh([
                'items',
                'table',
            ])
        );
    }

    /**
     * =====================================================
     * DELETE ORDER
     * =====================================================
     */
    public function destroy(
        Request $request,
        Order $order
    ): JsonResponse {

        $this->requirePermission(
            $order->restaurant,
            'orders.delete'
        );

        /**
         * Keep table reference BEFORE
         * deleting the order.
         */
        $table = $order->table;

        /**
         * Delete items first.
         */
        $order->items()->delete();

        /**
         * Delete order.
         */
        $order->delete();

        /**
         * Check table status after deletion.
         */
        if ($table) {

            $hasActiveOrder =
                $table
                    ->orders()
                    ->whereIn(
                        'status',
                        [
                            'pending',
                            'preparing',
                            'ready',
                        ]
                    )
                    ->exists();

            if (! $hasActiveOrder) {
                $table->update([
                    'status' => 'available',
                ]);
            }
        }

        return response()->json([
            'message' =>
                'Order deleted successfully.',
        ]);
    }

    /**
     * =====================================================
     * UPDATE ORDER STATUS
     * =====================================================
     */
    public function updateStatus(
        Request $request,
        Order $order
    ): OrderResource {

        $this->requirePermission(
            $order->restaurant,
            'orders.update'
        );

        $validated =
            $request->validate([
                'status' => [
                    'required',
                    'in:pending,preparing,ready,completed,cancelled',
                ],
            ]);

        $this->syncTableStatusAfterOrderStatusChange(
            $order,
            $validated['status']
        );

        $order->update(
            $validated
        );

        return new OrderResource(
            $order->fresh([
                'items',
                'table',
            ])
        );
    }

    /**
     * =====================================================
     * TABLE STATUS AFTER ORDER STATUS CHANGE
     * =====================================================
     */
    protected function syncTableStatusAfterOrderStatusChange(
        Order $order,
        string $newStatus
    ): void {

        if (
            $order->table_id === null
        ) {
            return;
        }

        /**
         * Make sure table relation exists.
         */
        $table = $order->table;

        if (! $table) {
            return;
        }

        /**
         * Active orders keep table reserved.
         */
        if (
            ! in_array(
                $newStatus,
                [
                    'completed',
                    'cancelled',
                ],
                true
            )
        ) {

            if (
                $table->status !==
                'reserved'
            ) {
                $table->update([
                    'status' =>
                        'reserved',
                ]);
            }

            return;
        }

        /**
         * Completed/cancelled:
         * check if another active order
         * still exists on this table.
         */
        $hasActiveOrder =
            $table
                ->orders()
                ->where(
                    'id',
                    '!=',
                    $order->id
                )
                ->whereIn(
                    'status',
                    [
                        'pending',
                        'preparing',
                        'ready',
                    ]
                )
                ->exists();

        if (
            ! $hasActiveOrder
        ) {
            $table->update([
                'status' =>
                    'available',
            ]);
        }
    }


}
 
