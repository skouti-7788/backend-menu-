<?php
 
namespace App\Http\Controllers\Api;
 
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
 
class StaffController extends Controller
{
    /**
     * Get all staff members of the current restaurant.
     *
     * Owner/admin always have access; staff need the
     * explicit 'staff.view' permission.
     */
    public function index(Request $request): JsonResponse
    {
        $restaurant = $this->resolveRestaurantForUser($request);
 
        $this->requirePermission($restaurant, 'staff.view');
 
        $staff = User::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('role', 'staff')
            ->with([
                'permissions:id,user_id,permission',
            ])
            ->latest()
            ->get([
                'id',
                'name',
                'email',
                'role',
                'restaurant_id',
                'created_at',
            ]);
 
        return response()->json([
            'staff' => $staff,
        ]);
    }
 
    /**
     * Create a new staff member.
     */
    public function store(Request $request): JsonResponse
    {
        $restaurant = $this->resolveRestaurantForUser($request);
 
        $this->requirePermission($restaurant, 'staff.add');
 
        $allowedPermissions = $this->allowedPermissionsList();
 
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
            ],
 
            'email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email',
            ],
 
            'password' => [
                'required',
                'string',
                'min:8',
                'max:255',
            ],
 
            'permissions' => [
                'nullable',
                'array',
            ],
 
            'permissions.*' => [
                'required',
                'string',
                Rule::in($allowedPermissions),
            ],
        ]);
 
        /*
        |--------------------------------------------------------------------------
        | Prevent privilege escalation.
        |--------------------------------------------------------------------------
        |
        | A staff member creating another staff member (via the
        | 'staff.add' permission) can only grant permissions they
        | themselves already hold. Only the owner/manager/admin
        | may grant any permission from the full catalog.
        |
        */
 
        $actor = $request->user();
 
        if (! $actor->isOwner() && ! $actor->isRestaurantManager() && ! $actor->isAdmin()) {
            $requested = collect($validated['permissions'] ?? []);
            $actorPermissions = $actor->permissions()->pluck('permission');
            $disallowed = $requested->diff($actorPermissions);
 
            if ($disallowed->isNotEmpty()) {
                abort(
                    403,
                    'You cannot grant permissions you do not have yourself: ' . $disallowed->implode(', ')
                );
            }
        }
 
        $staff = DB::transaction(function () use (
            $validated,
            $restaurant
        ) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make(
                    $validated['password']
                ),
                'role' => 'staff',
                'restaurant_id' => $restaurant->id,
            ]);
 
            $permissions = array_values(
                array_unique(
                    $validated['permissions'] ?? []
                )
            );
 
            if (! empty($permissions)) {
                $now = now();
 
                $rows = array_map(
                    function (string $permission) use (
                        $user,
                        $now
                    ) {
                        return [
                            'user_id' => $user->id,
                            'permission' => $permission,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    },
                    $permissions
                );
 
                UserPermission::insert($rows);
            }
 
            return $user;
        });
 
        $staff->load([
            'permissions:id,user_id,permission',
        ]);
 
        return response()->json([
            'message' => 'Staff member created successfully.',
            'staff' => [
                'id' => $staff->id,
                'name' => $staff->name,
                'email' => $staff->email,
                'role' => $staff->role,
                'restaurant_id' => $staff->restaurant_id,
                'permissions' => $staff->permissions
                    ->pluck('permission')
                    ->values()
                    ->toArray(),
            ],
        ], 201);
    }
 
    /**
     * Delete a staff member.
     */
    public function destroy(
        Request $request,
        User $staff
    ): JsonResponse {
        $restaurant = $this->resolveRestaurantForUser($request);
 
        $this->requirePermission($restaurant, 'staff.delete');
 
        $this->ensureStaffBelongsToRestaurant(
            $staff,
            $restaurant->id
        );
 
        DB::transaction(function () use ($staff) {
            /*
            | Delete permissions explicitly.
            | This is useful even if the database doesn't
            | have ON DELETE CASCADE configured.
            */
            $staff->permissions()->delete();
 
            /*
            | Revoke all Sanctum tokens.
            */
            $staff->tokens()->delete();
 
            $staff->delete();
        });
 
        return response()->json([
            'message' => 'Staff member deleted successfully.',
        ]);
    }
 
    /**
     * Get permissions of a staff member.
     */
    public function permissions(
        Request $request,
        User $staff
    ): JsonResponse {
        $restaurant = $this->resolveRestaurantForUser($request);
 
        $this->requirePermission($restaurant, 'staff.view');
 
        $this->ensureStaffBelongsToRestaurant(
            $staff,
            $restaurant->id
        );
 
        $permissions = $staff
            ->permissions()
            ->pluck('permission')
            ->values()
            ->toArray();
 
        return response()->json([
            'permissions' => $permissions,
        ]);
    }
 
    /**
     * Update permissions of a staff member.
     */
    public function updatePermissions(
        Request $request,
        User $staff
    ): JsonResponse {
        $restaurant = $this->resolveRestaurantForUser($request);
 
        $this->requirePermission($restaurant, 'staff.update');
 
        $this->ensureStaffBelongsToRestaurant(
            $staff,
            $restaurant->id
        );
 
        $allowedPermissions = $this->allowedPermissionsList();
 
        $data = $request->validate([
            'permissions' => [
                'required',
                'array',
            ],
 
            'permissions.*' => [
                'required',
                'string',
                Rule::in($allowedPermissions),
            ],
        ]);
 
        /*
        |--------------------------------------------------------------------------
        | Prevent privilege escalation (see store()).
        |--------------------------------------------------------------------------
        */
 
        $actor = $request->user();
 
        if (! $actor->isOwner() && ! $actor->isRestaurantManager() && ! $actor->isAdmin()) {
            $requested = collect($data['permissions']);
            $actorPermissions = $actor->permissions()->pluck('permission');
            $disallowed = $requested->diff($actorPermissions);
 
            if ($disallowed->isNotEmpty()) {
                abort(
                    403,
                    'You cannot grant permissions you do not have yourself: ' . $disallowed->implode(', ')
                );
            }
        }
 
        /*
        |--------------------------------------------------------------------------
        | Remove duplicates.
        |--------------------------------------------------------------------------
        */
 
        $permissions = array_values(
            array_unique(
                $data['permissions']
            )
        );
 
        /*
        |--------------------------------------------------------------------------
        | Sync permissions inside a transaction.
        |--------------------------------------------------------------------------
        */
 
        DB::transaction(function () use (
            $staff,
            $permissions
        ) {
            $staff->permissions()->delete();
 
            if (empty($permissions)) {
                return;
            }
 
            $now = now();
 
            $rows = array_map(
                function (string $permission) use (
                    $staff,
                    $now
                ) {
                    return [
                        'user_id' => $staff->id,
                        'permission' => $permission,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                },
                $permissions
            );
 
            UserPermission::insert($rows);
        });
 
        return response()->json([
            'message' => 'Permissions updated successfully.',
            'permissions' => $permissions,
        ]);
    }
 
    /**
     * Ensure that the target user is a staff member
     * belonging to the current restaurant.
     */
    private function ensureStaffBelongsToRestaurant(
        User $staff,
        int $restaurantId
    ): void {
        if (
            $staff->role !== 'staff'
            || (int) $staff->restaurant_id !== $restaurantId
        ) {
            abort(
                404,
                'Staff member not found.'
            );
        }
    }
 
    /**
     * List of permissions available for staff.
     */
    private function allowedPermissionsList(): array
    {
        return [
            'dashboard.view',
 
            'orders.view',
            'orders.add',
            'orders.update',
            'orders.delete',
 
            'meals.view',
            'meals.add',
            'meals.update',
            'meals.delete',
 
            'categories.view',
            'categories.add',
            'categories.update',
            'categories.delete',
 
            'tables.view',
            'tables.add',
            'tables.update',
            'tables.delete',
 
            'appearance.view',
            'appearance.update',
            'appearance.delete',
 
            'staff.view',
            'staff.add',
            'staff.update',
            'staff.delete',
 
            'qrcode.view',
 
            'profile.view',
            'profile.update',
        ];
    }
}
 