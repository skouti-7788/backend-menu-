<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
        $restaurant = $this->ownedRestaurant($request);

        $subscription = $restaurant->subscriptions()
            ->where('status', 'active')
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

    public function requestChange(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan' => ['required', 'string', Rule::in(array_keys(config('plans')))],
            'billing_interval' => ['required', 'string', Rule::in(['monthly', 'yearly'])],
        ]);

        $restaurant = $this->ownedRestaurant($request);
        $plan = config('plans.'.$validated['plan']);
        $amount = (int) $plan['price_'.$validated['billing_interval']];

        // Free Starter can be activated immediately. Paid plans stay pending
        // until a payment provider confirms the transaction.
        $isFree = $amount === 0;

        $subscription = $restaurant->subscriptions()->create([
            'plan' => $validated['plan'],
            'billing_interval' => $validated['billing_interval'],
            'status' => $isFree ? 'active' : 'pending_payment',
            'amount' => $amount,
            'currency' => 'MAD',
            'starts_at' => $isFree ? Carbon::now() : null,
        ]);

        return response()->json([
            'message' => $isFree
                ? 'Starter plan activated.'
                : 'Plan request created. Payment is required before activation.',
            'subscription' => $subscription,
            'payment_required' => ! $isFree,
        ], $isFree ? 201 : 202);
    }

    private function ownedRestaurant(Request $request): Restaurant
    {
        $user = $request->user();

        if (! $user || ! $user->isOwner()) {
            abort(403, 'Only the restaurant owner can manage the subscription.');
        }

        $restaurant = $user->restaurants()->latest()->first();

        if (! $restaurant) {
            abort(404, 'Restaurant not found.');
        }

        return $restaurant;
    }
}
