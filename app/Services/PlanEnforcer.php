<?php

namespace App\Services;

use App\Models\Restaurant;
use Illuminate\Http\Exceptions\HttpResponseException;

class PlanEnforcer
{
    public function planFor(Restaurant $restaurant): array
    {
        $subscription = $restaurant->subscriptions()
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>', now());
            })
            ->latest()
            ->first();

        $planKey = $subscription?->plan ?? 'starter';
        $plan = config('plans.'.$planKey);

        return $plan ?? config('plans.starter');
    }

    public function hasFeature(Restaurant $restaurant, string $feature): bool
    {
        return (bool) data_get(
            $this->planFor($restaurant),
            'features.'.$feature,
            false
        );
    }

    public function assertFeature(Restaurant $restaurant, string $feature): void
    {
        if (! $this->hasFeature($restaurant, $feature)) {
            throw new HttpResponseException(response()->json([
                'message' => 'This feature is not available in your current plan.',
                'code' => 'PLAN_FEATURE_LOCKED',
                'feature' => $feature,
                'required_plan' => $feature === 'staff' ? 'business' : 'pro',
            ], 403));
        }
    }

    public function assertLimit(Restaurant $restaurant, string $resource, int $currentCount): void
    {
        $plan = $this->planFor($restaurant);
        $limit = data_get($plan, 'limits.'.$resource);

        if ($limit !== null && $currentCount >= (int) $limit) {
            throw new HttpResponseException(response()->json([
                'message' => 'You have reached the limit of your current plan.',
                'code' => 'PLAN_LIMIT_REACHED',
                'resource' => $resource,
                'limit' => (int) $limit,
                'current' => $currentCount,
                'upgrade_required' => true,
            ], 403));
        }
    }
}
