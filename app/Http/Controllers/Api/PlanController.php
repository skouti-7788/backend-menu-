<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PlanController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'plans' => collect(config('plans'))->map(
                fn (array $plan, string $key) => [
                    'id' => $key,
                    ...$plan,
                ]
            )->values(),
            'currency' => 'MAD',
        ]);
    }

    public function current(Request $request): JsonResponse
    {
        $restaurant = $this->ownedRestaurant($request, $request->query('restaurant_id'));

        $subscription = $restaurant->subscriptions()
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            })
            ->latest()
            ->first();

        return response()->json([
            'subscription' => $subscription,
            'plan' => $subscription
                ? config('plans.'.$subscription->plan)
                : config('plans.starter'),
        ]);
    }

    public function cancel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'restaurant_id' => ['sometimes', 'integer', 'min:1'],
        ]);

        $restaurant = $this->ownedRestaurant($request, $validated['restaurant_id'] ?? null);

        $subscription = $restaurant->subscriptions()
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            })
            ->latest()
            ->first();

        if (! $subscription) {
            return response()->json([
                'message' => __('messages.subscription_missing'),
                'subscription' => null,
            ], 404);
        }

        if ($subscription->plan === 'starter') {
            return response()->json([
                'message' => __('messages.starter_cancel'),
                'subscription' => $subscription,
            ]);
        }

        if ($subscription->cancel_at_period_end) {
            return response()->json([
                'message' => __('messages.cancellation_already_scheduled'),
                'subscription' => $subscription,
            ]);
        }

        if (! $subscription->ends_at) {
            return response()->json([
                'message' => __('messages.cancellation_no_period'),
                'code' => 'SUBSCRIPTION_END_DATE_REQUIRED',
            ], 409);
        }

        $subscription->forceFill([
            'cancel_at_period_end' => true,
            'cancelled_at' => now(),
        ])->save();

        return response()->json([
            'message' => __('messages.cancellation_scheduled'),
            'subscription' => $subscription->fresh(),
            'access_until' => $subscription->ends_at,
        ]);
    }

    public function requestChange(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan' => ['required', 'string', Rule::in(array_keys(config('plans')))],
            'billing_interval' => ['required', 'string', Rule::in(['monthly', 'yearly'])],
            'restaurant_id' => ['sometimes', 'integer', 'min:1'],
        ]);

        $restaurant = $this->ownedRestaurant($request, $validated['restaurant_id'] ?? null);
        $plan = config('plans.'.$validated['plan']);
        $amount = (int) $plan['price_'.$validated['billing_interval']];

        // Only the free Starter plan can be activated while no free payment
        // provider is configured. Keep paid plans visible in the catalogue,
        // but never create a pending or active paid subscription.
        $isFree = $amount === 0;

        if (! $isFree) {
            return response()->json([
                'message' => __('messages.plans_coming_soon'),
                'payment_required' => true,
                'payment_available' => false,
                'subscription' => null,
            ], 409);
        }

        [$subscription, $alreadyActive] = DB::transaction(function () use (
            $restaurant,
            $validated,
            $amount,
            $isFree
        ) {
            $existingActive = $restaurant->subscriptions()
                ->where('status', 'active')
                ->where(function ($query) {
                    $query->whereNull('starts_at')
                        ->orWhere('starts_at', '<=', now());
                })
                ->where(function ($query) {
                    $query->whereNull('ends_at')
                        ->orWhere('ends_at', '>', now());
                })
                ->latest()
                ->first();

            if (
                $existingActive
                && $existingActive->plan === $validated['plan']
                && $existingActive->billing_interval === $validated['billing_interval']
            ) {
                return [$existingActive, true];
            }

            // A newer plan request replaces stale pending payment requests.
            $restaurant->subscriptions()
                ->where('status', 'pending_payment')
                ->update(['status' => 'cancelled']);

            if ($isFree) {
                // Only switch the active plan immediately for a zero-cost plan.
                $restaurant->subscriptions()
                    ->where('status', 'active')
                    ->update(['status' => 'cancelled']);
            }

            $created = $restaurant->subscriptions()->create([
                'plan' => $validated['plan'],
                'billing_interval' => $validated['billing_interval'],
                'status' => $isFree ? 'active' : 'pending_payment',
                'amount' => $amount,
                'currency' => 'MAD',
                'starts_at' => $isFree ? Carbon::now() : null,
            ]);

            return [$created, false];
        });

        return response()->json([
            'message' => $alreadyActive
                ? __('messages.plan_already_active')
                : ($isFree
                    ? __('messages.starter_activated')
                    : __('messages.plan_request_created')),
            'subscription' => $subscription,
            'payment_required' => ! $isFree && ! $alreadyActive,
        ], $alreadyActive ? 200 : ($isFree ? 201 : 202));
    }

    /**
     * Resolve only a restaurant owned by the authenticated owner.
     * An explicit ID supports owners with multiple restaurants; omitting it
     * preserves backwards compatibility by selecting their latest restaurant.
     */
    private function ownedRestaurant(Request $request, mixed $restaurantId = null): Restaurant
    {
        $user = $request->user();

        if (! $user || ! $user->isOwner()) {
            abort(403, __('messages.subscription_owner_only'));
        }

        $restaurants = $user->restaurants();

        if ($restaurantId !== null) {
            $restaurants->whereKey($restaurantId);
        }

        $restaurant = $restaurants->latest()->first();

        if (! $restaurant) {
            abort(404, __('messages.restaurant_not_found'));
        }

        return $restaurant;
    }
}
