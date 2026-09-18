<?php
 
namespace Tests\Feature;
 
use App\Models\Restaurant;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
 
class StaffManagingStaffTest extends TestCase
{
    use RefreshDatabase;
 
    private function makeRestaurant(): array
    {
        $owner = User::create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => 'secret123',
            'role' => 'owner',
        ]);
 
        $restaurant = Restaurant::create([
            'user_id' => $owner->id,
            'name' => 'Test Restaurant',
            'slug' => 'test-restaurant',
        ]);
 
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
 
    public function test_staff_without_staff_view_permission_cannot_list_staff(): void
    {
        [, $restaurant] = $this->makeRestaurant();
 
        $staff = $this->makeStaffWithPermissions($restaurant, ['meals.view'], 'noaccess@example.com');
 
        $response = $this->actingAs($staff, 'sanctum')
            ->getJson("/api/staff");
 
        $response->assertStatus(403);
    }
 
    public function test_staff_with_staff_view_permission_can_list_staff(): void
    {
        [, $restaurant] = $this->makeRestaurant();
 
        $staff = $this->makeStaffWithPermissions($restaurant, ['staff.view'], 'hasaccess@example.com');
 
        $response = $this->actingAs($staff, 'sanctum')
            ->getJson("/api/staff");
 
        $response->assertOk();
    }
 
    public function test_staff_with_staff_add_cannot_grant_a_permission_they_do_not_have(): void
    {
        [, $restaurant] = $this->makeRestaurant();
 
        // This staff member can add staff, but only holds 'meals.view'
        // themselves. They must not be able to grant 'staff.delete'
        // (a permission they don't have) to a new hire.
        $recruiter = $this->makeStaffWithPermissions(
            $restaurant,
            ['staff.add', 'meals.view'],
            'recruiter@example.com'
        );
 
        $response = $this->actingAs($recruiter, 'sanctum')
            ->postJson("/api/staff", [
                'name' => 'New Hire',
                'email' => 'newhire@example.com',
                'password' => 'password123',
                'permissions' => ['meals.view', 'staff.delete'],
            ]);
 
        $response->assertStatus(403);
 
        $this->assertDatabaseMissing('users', [
            'email' => 'newhire@example.com',
        ]);
    }
 
    public function test_staff_with_staff_add_can_grant_a_permission_they_do_have(): void
    {
        [, $restaurant] = $this->makeRestaurant();
 
        $recruiter = $this->makeStaffWithPermissions(
            $restaurant,
            ['staff.add', 'meals.view'],
            'recruiter2@example.com'
        );
 
        $response = $this->actingAs($recruiter, 'sanctum')
            ->postJson("/api/staff", [
                'name' => 'New Hire',
                'email' => 'newhire2@example.com',
                'password' => 'password123',
                'permissions' => ['meals.view'],
            ]);
 
        $response->assertStatus(201);
 
        $this->assertDatabaseHas('users', [
            'email' => 'newhire2@example.com',
        ]);
    }
 
    public function test_owner_can_still_grant_any_permission_regardless_of_their_own(): void
    {
        [$owner, $restaurant] = $this->makeRestaurant();
 
        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/staff", [
                'name' => 'New Hire',
                'email' => 'newhire3@example.com',
                'password' => 'password123',
                'permissions' => ['staff.delete', 'appearance.delete'],
            ]);
 
        $response->assertStatus(201);
    }
}
 