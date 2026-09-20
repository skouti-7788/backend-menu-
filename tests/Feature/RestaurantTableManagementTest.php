<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestaurantTableManagementTest extends TestCase
{
    use RefreshDatabase;

    private function makeRestaurantWithOwner(string $suffix): array
    {
        $owner = User::create([
            'name' => "Owner {$suffix}",
            'email' => "owner{$suffix}@example.com",
            'password' => 'secret123',
            'role' => 'owner',
        ]);

        $restaurant = Restaurant::create([
            'user_id' => $owner->id,
            'name' => "Restaurant {$suffix}",
            'slug' => "restaurant-{$suffix}",
        ]);

        $owner->restaurant_id = $restaurant->id;
        $owner->save();

        return [$owner, $restaurant];
    }

    private function makeStaffWithPermissions(Restaurant $restaurant, array $permissions, string $email): User
    {
        $staff = User::create([
            'name' => 'Staff ' . $email,
            'email' => $email,
            'password' => 'secret123',
            'role' => 'staff',
            'restaurant_id' => $restaurant->id,
        ]);

        foreach ($permissions as $permission) {
            UserPermission::create([
                'user_id' => $staff->id,
                'permission' => $permission,
            ]);
        }

        return $staff;
    }

    public function test_owner_can_create_a_table(): void
    {
        [$owner, $restaurant] = $this->makeRestaurantWithOwner('a');

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/restaurants/{$restaurant->id}/tables", [
                'number' => 1,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('restaurant_tables', [
            'restaurant_id' => $restaurant->id,
            'number' => 1,
            'status' => 'available',
        ]);
    }

    public function test_table_numbers_must_be_unique_within_the_same_restaurant(): void
    {
        [$owner, $restaurant] = $this->makeRestaurantWithOwner('a');

        RestaurantTable::create([
            'restaurant_id' => $restaurant->id,
            'number' => 1,
            'name' => 'Table 1',
            'status' => 'available',
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/restaurants/{$restaurant->id}/tables", [
                'number' => 1,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['number']);
    }

    public function test_same_table_number_is_allowed_across_different_restaurants(): void
    {
        [$ownerA, $restaurantA] = $this->makeRestaurantWithOwner('a');
        [, $restaurantB] = $this->makeRestaurantWithOwner('b');

        RestaurantTable::create([
            'restaurant_id' => $restaurantB->id,
            'number' => 1,
            'name' => 'Table 1',
            'status' => 'available',
        ]);

        $response = $this->actingAs($ownerA, 'sanctum')
            ->postJson("/api/restaurants/{$restaurantA->id}/tables", [
                'number' => 1,
            ]);

        $response->assertStatus(201);
    }

    public function test_bulk_creating_tables_continues_numbering_from_the_current_max(): void
    {
        [$owner, $restaurant] = $this->makeRestaurantWithOwner('a');

        RestaurantTable::create([
            'restaurant_id' => $restaurant->id,
            'number' => 5,
            'name' => 'Table 5',
            'status' => 'available',
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/restaurants/{$restaurant->id}/tables/bulk", [
                'count' => 3,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('restaurant_tables', ['restaurant_id' => $restaurant->id, 'number' => 6]);
        $this->assertDatabaseHas('restaurant_tables', ['restaurant_id' => $restaurant->id, 'number' => 7]);
        $this->assertDatabaseHas('restaurant_tables', ['restaurant_id' => $restaurant->id, 'number' => 8]);
    }

    public function test_staff_without_tables_view_permission_cannot_list_tables(): void
    {
        [, $restaurant] = $this->makeRestaurantWithOwner('a');
        $staff = $this->makeStaffWithPermissions($restaurant, [], 'staffnoview@example.com');

        $response = $this->actingAs($staff, 'sanctum')
            ->getJson("/api/restaurants/{$restaurant->id}/tables");

        $response->assertStatus(403);
    }

    public function test_owner_of_one_restaurant_cannot_view_a_table_of_another_restaurant(): void
    {
        [$ownerA, $restaurantA] = $this->makeRestaurantWithOwner('a');
        [, $restaurantB] = $this->makeRestaurantWithOwner('b');

        $tableB = RestaurantTable::create([
            'restaurant_id' => $restaurantB->id,
            'number' => 1,
            'name' => 'Table 1',
            'status' => 'available',
        ]);

        $response = $this->actingAs($ownerA, 'sanctum')
            ->getJson("/api/restaurants/{$restaurantA->id}/tables/{$tableB->id}");

        $response->assertStatus(403);
    }

    public function test_owner_can_update_table_status(): void
    {
        [$owner, $restaurant] = $this->makeRestaurantWithOwner('a');

        $table = RestaurantTable::create([
            'restaurant_id' => $restaurant->id,
            'number' => 1,
            'name' => 'Table 1',
            'status' => 'available',
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->putJson("/api/restaurants/{$restaurant->id}/tables/{$table->id}", [
                'number' => 1,
                'name' => 'Table 1',
                'status' => 'occupied',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('restaurant_tables', [
            'id' => $table->id,
            'status' => 'occupied',
        ]);
    }

    public function test_owner_can_delete_a_table(): void
    {
        [$owner, $restaurant] = $this->makeRestaurantWithOwner('a');

        $table = RestaurantTable::create([
            'restaurant_id' => $restaurant->id,
            'number' => 1,
            'name' => 'Table 1',
            'status' => 'available',
        ]);

        $response = $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/restaurants/{$restaurant->id}/tables/{$table->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('restaurant_tables', ['id' => $table->id]);
    }

    public function test_owner_can_delete_all_tables_of_their_restaurant(): void
    {
        [$owner, $restaurant] = $this->makeRestaurantWithOwner('a');

        RestaurantTable::create(['restaurant_id' => $restaurant->id, 'number' => 1, 'name' => 'T1', 'status' => 'available']);
        RestaurantTable::create(['restaurant_id' => $restaurant->id, 'number' => 2, 'name' => 'T2', 'status' => 'available']);

        $response = $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/restaurants/{$restaurant->id}/tables/all");

        $response->assertStatus(200);
        $this->assertDatabaseCount('restaurant_tables', 0);
    }
    public function test_owner_cannot_delete_all_tables_of_another_restaurant(): void
    {
        [$ownerA, $restaurantA] = $this->makeRestaurantWithOwner('a');
        [, $restaurantB] = $this->makeRestaurantWithOwner('b');

        $tableB1 = RestaurantTable::create([
            'restaurant_id' => $restaurantB->id,
            'number' => 1,
            'name' => 'Table B1',
            'status' => 'available',
        ]);

        $tableB2 = RestaurantTable::create([
            'restaurant_id' => $restaurantB->id,
            'number' => 2,
            'name' => 'Table B2',
            'status' => 'available',
        ]);

        $response = $this->actingAs($ownerA, 'sanctum')
            ->deleteJson("/api/restaurants/{$restaurantB->id}/tables/all");

        $response->assertStatus(403);

        $this->assertDatabaseHas('restaurant_tables', [
            'id' => $tableB1->id,
            'restaurant_id' => $restaurantB->id,
        ]);

        $this->assertDatabaseHas('restaurant_tables', [
            'id' => $tableB2->id,
            'restaurant_id' => $restaurantB->id,
        ]);
    }
}
