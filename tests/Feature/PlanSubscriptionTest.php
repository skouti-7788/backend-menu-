<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private function createOwnerWithRestaurant(): array
    {
        $owner = User::create([
            'name' => 'Plan Owner',
            'email' => 'plan-owner@example.com',
            'password' => 'password123',
            'role' => 'owner',
        ]);

        $restaurant = Restaurant::create([
            'user_id' => $owner->id,
            'name' => 'Plan Test Restaurant',
            'slug' => 'plan-test-restaurant',
        ]);

        return [$owner, $restaurant];
    }

    public function test_public_plans_endpoint_returns_all_three_plans(): void
    {
        $response = $this->getJson('/api/plans');

        $response->assertOk()
            ->assertJsonCount(3, 'plans')
            ->assertJsonPath('currency', 'MAD')
            ->assertJsonPath('plans.0.id', 'starter');
    }

    public function test_owner_can_activate_free_starter_plan(): void
    {
        [$owner, $restaurant] = $this->createOwnerWithRestaurant();

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/subscription', [
                'plan' => 'starter',
                'billing_interval' => 'monthly',
            ]);

        $response->assertCreated()
            ->assertJsonPath('payment_required', false)
            ->assertJsonPath('subscription.plan', 'starter')
            ->assertJsonPath('subscription.status', 'active');

        $this->assertDatabaseHas('restaurant_subscriptions', [
            'restaurant_id' => $restaurant->id,
            'plan' => 'starter',
            'status' => 'active',
            'amount' => 0,
        ]);
    }

    public function test_paid_plan_stays_pending_until_payment_is_confirmed(): void
    {
        [$owner, $restaurant] = $this->createOwnerWithRestaurant();

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/subscription', [
                'plan' => 'pro',
                'billing_interval' => 'yearly',
            ]);

        $response->assertStatus(202)
            ->assertJsonPath('payment_required', true)
            ->assertJsonPath('subscription.plan', 'pro')
            ->assertJsonPath('subscription.status', 'pending_payment')
            ->assertJsonPath('subscription.amount', 990);

        $this->assertDatabaseHas('restaurant_subscriptions', [
            'restaurant_id' => $restaurant->id,
            'plan' => 'pro',
            'status' => 'pending_payment',
            'amount' => 990,
        ]);
    }

    public function test_subscription_endpoints_require_authentication(): void
    {
        $this->getJson('/api/subscription')->assertUnauthorized();

        $this->postJson('/api/subscription', [
            'plan' => 'starter',
            'billing_interval' => 'monthly',
        ])->assertUnauthorized();
    }

    public function test_owner_can_read_current_active_subscription(): void
    {
        [$owner, $restaurant] = $this->createOwnerWithRestaurant();

        $restaurant->subscriptions()->create([
            'plan' => 'business',
            'billing_interval' => 'monthly',
            'status' => 'active',
            'amount' => 199,
            'currency' => 'MAD',
            'starts_at' => now(),
        ]);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/subscription')
            ->assertOk()
            ->assertJsonPath('subscription.plan', 'business')
            ->assertJsonPath('plan.name', 'Business');
    }
    public function test_expired_subscription_falls_back_to_starter_features(): void
    {
        [$owner, $restaurant] = $this->createOwnerWithRestaurant();

        $restaurant->subscriptions()->create([
            'plan' => 'business',
            'billing_interval' => 'monthly',
            'status' => 'active',
            'amount' => 199,
            'currency' => 'MAD',
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
        ]);

        $plan = app(\App\Services\PlanEnforcer::class)->planFor($restaurant);

        $this->assertSame('Starter', $plan['name']);
        $this->assertFalse($plan['features']['staff']);
    }

    public function test_restaurant_manager_cannot_change_subscription(): void
    {
        $manager = User::create([
            'name' => 'Restaurant Manager',
            'email' => 'plan-manager@example.com',
            'password' => 'password123',
            'role' => 'restaurant_manager',
        ]);

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/subscription', [
                'plan' => 'starter',
                'billing_interval' => 'monthly',
            ])
            ->assertForbidden();
    }

    public function test_requesting_the_same_active_plan_is_idempotent(): void
    {
        [$owner, $restaurant] = $this->createOwnerWithRestaurant();

        $payload = [
            'plan' => 'starter',
            'billing_interval' => 'monthly',
        ];

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/subscription', $payload)
            ->assertCreated();

        $this->postJson('/api/subscription', $payload)
            ->assertOk()
            ->assertJsonPath('payment_required', false);

        $this->assertSame(
            1,
            $restaurant->subscriptions()->where('plan', 'starter')->count()
        );
    }

    public function test_paid_plan_request_does_not_cancel_current_active_plan(): void
    {
        [$owner, $restaurant] = $this->createOwnerWithRestaurant();

        $restaurant->subscriptions()->create([
            'plan' => 'starter',
            'billing_interval' => 'monthly',
            'status' => 'active',
            'amount' => 0,
            'currency' => 'MAD',
            'starts_at' => now(),
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/subscription', [
                'plan' => 'pro',
                'billing_interval' => 'monthly',
            ])
            ->assertStatus(202);

        $this->assertDatabaseHas('restaurant_subscriptions', [
            'restaurant_id' => $restaurant->id,
            'plan' => 'starter',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('restaurant_subscriptions', [
            'restaurant_id' => $restaurant->id,
            'plan' => 'pro',
            'status' => 'pending_payment',
        ]);
    }

    public function test_owner_can_switch_back_to_starter_and_cancel_previous_active_plan(): void
    {
        [$owner, $restaurant] = $this->createOwnerWithRestaurant();

        $restaurant->subscriptions()->create([
            'plan' => 'pro',
            'billing_interval' => 'monthly',
            'status' => 'active',
            'amount' => 99,
            'currency' => 'MAD',
            'starts_at' => now()->subMonth(),
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/subscription', [
                'plan' => 'starter',
                'billing_interval' => 'monthly',
            ])
            ->assertCreated()
            ->assertJsonPath('subscription.status', 'active');

        $this->assertDatabaseHas('restaurant_subscriptions', [
            'restaurant_id' => $restaurant->id,
            'plan' => 'pro',
            'status' => 'cancelled',
        ]);

        $this->assertDatabaseHas('restaurant_subscriptions', [
            'restaurant_id' => $restaurant->id,
            'plan' => 'starter',
            'status' => 'active',
        ]);
    }

    public function test_owner_cannot_request_unknown_plan_or_billing_interval(): void
    {
        [$owner] = $this->createOwnerWithRestaurant();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/subscription', [
                'plan' => 'enterprise',
                'billing_interval' => 'monthly',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['plan']);

        $this->postJson('/api/subscription', [
            'plan' => 'pro',
            'billing_interval' => 'weekly',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['billing_interval']);
    }

}
