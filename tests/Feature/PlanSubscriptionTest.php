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
}
