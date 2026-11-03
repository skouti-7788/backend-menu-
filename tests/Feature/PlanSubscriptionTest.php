<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\User;
use App\Services\PlanEnforcer;
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

    public function test_paid_plan_is_unavailable_until_a_free_payment_provider_exists(): void
    {
        [$owner, $restaurant] = $this->createOwnerWithRestaurant();

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/subscription', [
                'plan' => 'pro',
                'billing_interval' => 'yearly',
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('payment_required', true)
            ->assertJsonPath('payment_available', false)
            ->assertJsonPath('subscription', null);

        $this->assertDatabaseMissing('restaurant_subscriptions', [
            'restaurant_id' => $restaurant->id,
            'plan' => 'pro',
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

        $plan = app(PlanEnforcer::class)->planFor($restaurant);

        $this->assertSame('Starter', $plan['name']);
        $this->assertFalse($plan['features']['staff']);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/subscription')
            ->assertOk()
            ->assertJsonPath('subscription', null)
            ->assertJsonPath('plan.name', 'Starter');
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
            ->assertStatus(409)
            ->assertJsonPath('payment_available', false);

        $this->assertDatabaseHas('restaurant_subscriptions', [
            'restaurant_id' => $restaurant->id,
            'plan' => 'starter',
            'status' => 'active',
        ]);

        $this->assertDatabaseMissing('restaurant_subscriptions', [
            'restaurant_id' => $restaurant->id,
            'plan' => 'pro',
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

    public function test_future_dated_active_subscription_does_not_grant_paid_features(): void
    {
        [$owner, $restaurant] = $this->createOwnerWithRestaurant();

        $restaurant->subscriptions()->create([
            'plan' => 'business',
            'billing_interval' => 'monthly',
            'status' => 'active',
            'amount' => 199,
            'currency' => 'MAD',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addMonth(),
        ]);

        $plan = app(\App\Services\PlanEnforcer::class)->planFor($restaurant);

        $this->assertSame('Starter', $plan['name']);
        $this->assertFalse($plan['features']['orders']);
        $this->assertFalse($plan['features']['tables']);
        $this->assertFalse($plan['features']['staff']);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/subscription')
            ->assertOk()
            ->assertJsonPath('subscription', null)
            ->assertJsonPath('plan.name', 'Starter');
    }


    public function test_owner_can_select_one_of_their_own_restaurants_for_subscription(): void
    {
        [$owner, $firstRestaurant] = $this->createOwnerWithRestaurant();

        $secondRestaurant = Restaurant::create([
            'user_id' => $owner->id,
            'name' => 'Second Plan Restaurant',
            'slug' => 'second-plan-restaurant',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/subscription', [
                'plan' => 'starter',
                'billing_interval' => 'monthly',
                'restaurant_id' => $secondRestaurant->id,
            ])
            ->assertCreated()
            ->assertJsonPath('subscription.restaurant_id', $secondRestaurant->id);

        $this->assertDatabaseHas('restaurant_subscriptions', [
            'restaurant_id' => $secondRestaurant->id,
            'plan' => 'starter',
            'status' => 'active',
        ]);

        $this->assertDatabaseMissing('restaurant_subscriptions', [
            'restaurant_id' => $firstRestaurant->id,
        ]);
    }

    public function test_owner_cannot_read_or_change_another_owners_restaurant_subscription(): void
    {
        [$owner] = $this->createOwnerWithRestaurant();

        $otherOwner = User::create([
            'name' => 'Other Plan Owner',
            'email' => 'other-plan-owner@example.com',
            'password' => 'password123',
            'role' => 'owner',
        ]);

        $otherRestaurant = Restaurant::create([
            'user_id' => $otherOwner->id,
            'name' => 'Private Restaurant',
            'slug' => 'private-plan-restaurant',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/subscription?restaurant_id='.$otherRestaurant->id)
            ->assertNotFound();

        $this->postJson('/api/subscription', [
            'plan' => 'starter',
            'billing_interval' => 'monthly',
            'restaurant_id' => $otherRestaurant->id,
        ])->assertNotFound();

        $this->assertDatabaseMissing('restaurant_subscriptions', [
            'restaurant_id' => $otherRestaurant->id,
        ]);
    }

    public function test_owner_can_read_current_subscription_for_selected_owned_restaurant(): void
    {
        [$owner] = $this->createOwnerWithRestaurant();

        $secondRestaurant = Restaurant::create([
            'user_id' => $owner->id,
            'name' => 'Second Current Restaurant',
            'slug' => 'second-current-restaurant',
        ]);

        $secondRestaurant->subscriptions()->create([
            'plan' => 'starter',
            'billing_interval' => 'monthly',
            'status' => 'active',
            'amount' => 0,
            'currency' => 'MAD',
            'starts_at' => now(),
        ]);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/subscription?restaurant_id='.$secondRestaurant->id)
            ->assertOk()
            ->assertJsonPath('subscription.restaurant_id', $secondRestaurant->id)
            ->assertJsonPath('plan.name', 'Starter');
    }


    public function test_owner_can_schedule_paid_subscription_cancellation_at_period_end(): void
    {
        [$owner, $restaurant] = $this->createOwnerWithRestaurant();

        $subscription = $restaurant->subscriptions()->create([
            'plan' => 'pro',
            'billing_interval' => 'monthly',
            'status' => 'active',
            'amount' => 99,
            'currency' => 'MAD',
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->addDays(20),
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/subscription/cancel')
            ->assertOk()
            ->assertJsonPath('subscription.id', $subscription->id)
            ->assertJsonPath('subscription.cancel_at_period_end', true);

        $this->assertDatabaseHas('restaurant_subscriptions', [
            'id' => $subscription->id,
            'status' => 'active',
            'cancel_at_period_end' => true,
        ]);

        $this->getJson('/api/subscription')
            ->assertOk()
            ->assertJsonPath('subscription.plan', 'pro')
            ->assertJsonPath('plan.name', 'Pro');
    }

    public function test_repeating_subscription_cancellation_is_idempotent(): void
    {
        [$owner, $restaurant] = $this->createOwnerWithRestaurant();

        $subscription = $restaurant->subscriptions()->create([
            'plan' => 'pro',
            'billing_interval' => 'monthly',
            'status' => 'active',
            'amount' => 99,
            'currency' => 'MAD',
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->addDays(20),
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/subscription/cancel')
            ->assertOk()
            ->assertJsonPath('subscription.cancel_at_period_end', true);

        $cancelledAt = $subscription->fresh()->cancelled_at?->toISOString();

        $this->postJson('/api/subscription/cancel')
            ->assertOk()
            ->assertJsonPath('subscription.cancel_at_period_end', true);

        $this->assertSame($cancelledAt, $subscription->fresh()->cancelled_at?->toISOString());
    }

    public function test_owner_cannot_cancel_another_owners_subscription(): void
    {
        [$owner] = $this->createOwnerWithRestaurant();
        $otherOwner = User::create([
            'name' => 'Cancellation Other Owner',
            'email' => 'cancel-other-owner@example.com',
            'password' => 'password123',
            'role' => 'owner',
        ]);
        $otherRestaurant = Restaurant::create([
            'user_id' => $otherOwner->id,
            'name' => 'Other Cancellation Restaurant',
            'slug' => 'other-cancellation-restaurant',
        ]);
        $otherSubscription = $otherRestaurant->subscriptions()->create([
            'plan' => 'pro',
            'billing_interval' => 'monthly',
            'status' => 'active',
            'amount' => 99,
            'currency' => 'MAD',
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(25),
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/subscription/cancel', [
                'restaurant_id' => $otherRestaurant->id,
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('restaurant_subscriptions', [
            'id' => $otherSubscription->id,
            'cancel_at_period_end' => false,
        ]);
    }

    public function test_starter_subscription_does_not_need_cancellation(): void
    {
        [$owner, $restaurant] = $this->createOwnerWithRestaurant();
        $subscription = $restaurant->subscriptions()->create([
            'plan' => 'starter',
            'billing_interval' => 'monthly',
            'status' => 'active',
            'amount' => 0,
            'currency' => 'MAD',
            'starts_at' => now(),
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/subscription/cancel')
            ->assertOk()
            ->assertJsonPath('subscription.id', $subscription->id)
            ->assertJsonPath('subscription.cancel_at_period_end', false);
    }

    public function test_cancellation_requires_a_known_billing_period_end(): void
    {
        [$owner, $restaurant] = $this->createOwnerWithRestaurant();
        $subscription = $restaurant->subscriptions()->create([
            'plan' => 'pro',
            'billing_interval' => 'monthly',
            'status' => 'active',
            'amount' => 99,
            'currency' => 'MAD',
            'starts_at' => now()->subDays(5),
            'ends_at' => null,
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/subscription/cancel')
            ->assertStatus(409)
            ->assertJsonPath('code', 'SUBSCRIPTION_END_DATE_REQUIRED');

        $this->assertDatabaseHas('restaurant_subscriptions', [
            'id' => $subscription->id,
            'cancel_at_period_end' => false,
        ]);
    }

}
