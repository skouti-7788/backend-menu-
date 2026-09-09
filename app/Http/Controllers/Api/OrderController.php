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

        $items = collect(
            $request->input('items', [])
        );

        $order = DB::transaction(
            function () use (
                $restaurant,
                $request,
                $items
            ) {

                $order = Order::create([
                    'restaurant_id' =>
                        $restaurant->id,

                    'customer_name' =>
                        $request->customer_name,

                    'phone' =>
                        $request->phone,

                    'address' =>
                        $request->address,

                    'status' =>
                        $request->status
                            ? OrderStatus::from(
                                $request->status
                            )
                            : OrderStatus::PENDING,

                    'total' => 0,

                    'table_id' =>
                        $request->table_id,
                ]);

                $subtotal = 0;

                foreach (
                    $items as $item
                ) {

                    /**
                     * Important security:
                     *
                     * The meal MUST belong
                     * to this restaurant.
                     */
                    $meal =
                        $restaurant
                            ->meals()
                            ->whereKey(
                                $item['meal_id']
                            )
                            ->where(
                                'status',
                                'active'
                            )
                            ->firstOrFail();

                    $quantity =
                        (int) (
                            $item['quantity']
                            ?? 0
                        );

                    $lineTotal =
                        (float) $meal->price
                        * $quantity;

                    $subtotal +=
                        $lineTotal;

                    OrderItem::create([
                        'order_id' =>
                            $order->id,

                        'meal_id' =>
                            $meal->id,

                        'quantity' =>
                            $quantity,

                        'unit_price' =>
                            $meal->price,

                        'total_price' =>
                            $lineTotal,

                        'notes' =>
                            (string) (
                                $item['notes']
                                ?? ''
                            ),
                    ]);
                }

                /**
                 * Tax
                 */
                $tax = round(
                    $subtotal * 0.09,
                    2
                );

                $total = round(
                    $subtotal + $tax,
                    2
                );

                $order->update([
                    'total' => $total,
                ]);

                return $order->load([
                    'items',
                    'table',
                ]);
            }
        );

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

    /**
     * =====================================================
     * REQUIRE PERMISSION
     * =====================================================
     *
     * ADMIN
     *   -> full access
     *
     * OWNER
     *   -> access to his restaurants
     *
     * STAFF
     *   -> access only to his restaurant
     *   -> requires specific permission
     */
    protected function requirePermission(
        Restaurant $restaurant,
        string $permission
    ): void {

        $user = auth()->user();

        /**
         * Not authenticated.
         */
        if (! $user) {
            abort(
                401,
                'Unauthenticated.'
            );
        }

        /**
         * =================================================
         * ADMIN
         * =================================================
         */
        if (
            $user->isAdmin()
        ) {
            return;
        }

        /**
         * =================================================
         * OWNER
         * =================================================
         *
         * Restaurant is owned by user.
         */
        if (
            $user->isOwner()
        ) {

            if (
                (int) $restaurant->user_id !==
                (int) $user->id
            ) {
                abort(
                    403,
                    'You are not authorized to manage this restaurant.'
                );
            }

            /**
             * Owner has all permissions.
             */
            return;
        }

        /**
         * =================================================
         * STAFF
         * =================================================
         */
        if (
            $user->isStaff()
        ) {

            /**
             * Staff can ONLY access
             * his assigned restaurant.
             */
            if (
                (int) $user->restaurant_id !==
                (int) $restaurant->id
            ) {
                abort(
                    403,
                    'You are not authorized to manage this restaurant.'
                );
            }

            /**
             * Check permission.
             */
            if (
                ! $user->hasPermission(
                    $permission
                )
            ) {
                abort(
                    403,
                    'You do not have permission to perform this action.'
                );
            }

            return;
        }

        /**
         * =================================================
         * RESTAURANT MANAGER
         * =================================================
         *
         * Kept for compatibility with
         * your previous architecture.
         */
        if (
            $user->isRestaurantManager()
        ) {

            if (
                (int) $restaurant->user_id !==
                (int) $user->id
            ) {
                abort(
                    403,
                    'You are not authorized to manage this restaurant.'
                );
            }

            return;
        }

        /**
         * Unknown role.
         */
        abort(
            403,
            'You are not authorized to manage this restaurant.'
        );
    }
}
 
