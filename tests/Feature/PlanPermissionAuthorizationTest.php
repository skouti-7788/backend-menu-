<?php

namespace Tests\Feature;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Tests\TestCase;

final class ExposedPlanPermissionController extends Controller
{
    public function check(Restaurant $restaurant, string $permission): void
    {
        $this->requirePermission($restaurant, $permission);
    }
}

class PlanPermissionAuthorizationTest extends TestCase
{
    protected function makeRestaurantOwner(int $id = 10): User
    {
        $user = new User();
        $user->id = $id;
        $user->role = 'owner';

        return $user;
    }

    protected function makeStarterRestaurant(int $ownerId = 10): Restaurant
    {
        $restaurant = new Restaurant();
        $restaurant->id = 20;
        $restaurant->user_id = $ownerId;
        $restaurant->plan = 'starter';

        return $restaurant;
    }

    public function test_owner_is_denied_pro_only_table_permissions_on_starter(): void
    {
        auth()->login($this->makeRestaurantOwner());

        try {
            (new ExposedPlanPermissionController())->check(
                $this->makeStarterRestaurant(),
                'tables.add'
            );

            self::fail('Starter owners must not create tables.');
        } catch (HttpResponseException $exception) {
            self::assertSame(403, $exception->getResponse()->getStatusCode());
            self::assertSame(
                'PLAN_UPGRADE_REQUIRED',
                $exception->getResponse()->getData(true)['code']
            );
        }
    }

    public function test_owner_can_use_table_permissions_on_pro(): void
    {
        auth()->login($this->makeRestaurantOwner());

        $restaurant = $this->makeStarterRestaurant();
        $restaurant->plan = 'pro';

        (new ExposedPlanPermissionController())->check($restaurant, 'tables.add');

        self::assertTrue(true);
    }

    public function test_user_cannot_learn_plan_availability_for_another_restaurant(): void
    {
        $user = $this->makeRestaurantOwner(11);
        auth()->login($user);

        try {
            (new ExposedPlanPermissionController())->check(
                $this->makeStarterRestaurant(10),
                'tables.add'
            );

            self::fail('A user must not access another restaurant.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_restaurant_manager_cannot_access_another_restaurant(): void
    {
        $manager = new User();
        $manager->id = 30;
        $manager->role = 'restaurant_manager';
        $manager->restaurant_id = 999;
        auth()->login($manager);

        try {
            (new ExposedPlanPermissionController())->check(
                $this->makeStarterRestaurant(10),
                'tables.view'
            );

            self::fail('A manager must not access a restaurant they do not belong to.');
        } catch (\\Symfony\\Component\\HttpKernel\\Exception\\HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_staff_cannot_access_another_restaurant(): void
    {
        $staff = new User();
        $staff->id = 31;
        $staff->role = 'staff';
        $staff->restaurant_id = 999;
        auth()->login($staff);

        try {
            (new ExposedPlanPermissionController())->check(
                $this->makeStarterRestaurant(10),
                'orders.view'
            );

            self::fail('Staff must not access a restaurant they do not belong to.');
        } catch (\\Symfony\\Component\\HttpKernel\\Exception\\HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_admin_can_access_plan_restricted_features(): void
    {
        $admin = new User();
        $admin->id = 99;
        $admin->role = 'admin';
        auth()->login($admin);

        (new ExposedPlanPermissionController())->check(
            $this->makeStarterRestaurant(),
            'tables.add'
        );

        self::assertTrue(true);
    }
}
