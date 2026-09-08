<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\JsonResponse;
use App\Models\UserPermission;

class StaffController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $restaurant = $this->resolveRestaurantForUser($request);

        $this->authorizeOwner($restaurant);

        $staff = User::where('restaurant_id', $restaurant->id)
            ->where('role', 'staff')
            ->get(['id', 'name', 'email', 'role', 'restaurant_id', 'created_at']);

        return response()->json(['staff' => $staff]);
    }

    public function store(Request $request): JsonResponse
    {
        $restaurant = $this->resolveRestaurantForUser($request);

        $this->authorizeOwner($restaurant);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => 'staff',
            'restaurant_id' => $restaurant->id,
        ]);

        // Optionally assign permissions if provided (validated below)
        $perms = $request->input('permissions', []);
        if (is_array($perms) && count($perms) > 0) {
            $allowed = $this->allowedPermissionsList();
            $toInsert = [];
            foreach (array_unique($perms) as $p) {
                if (in_array($p, $allowed, true)) {
                    $toInsert[] = ['user_id' => $user->id, 'permission' => $p, 'created_at' => now(), 'updated_at' => now()];
                }
            }

            if (count($toInsert) > 0) {
                UserPermission::insert($toInsert);
            }
        }

        return response()->json(['staff' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'restaurant_id' => $user->restaurant_id,
        ]], 201);
    }

    public function destroy(Request $request, User $staff): JsonResponse
    {
        $restaurant = $this->resolveRestaurantForUser($request);

        $this->authorizeOwner($restaurant);

        // Ensure the staff belongs to this restaurant
        if ($staff->restaurant_id !== $restaurant->id || $staff->role !== 'staff') {
            abort(404, 'Staff member not found.');
        }

        $staff->delete();

        return response()->json(['message' => 'Staff member deleted.']);
    }

    public function permissions(Request $request, User $staff): JsonResponse
    {
        $restaurant = $this->resolveRestaurantForUser($request);

        $this->authorizeOwner($restaurant);

        if ($staff->restaurant_id !== $restaurant->id || $staff->role !== 'staff') {
            abort(404, 'Staff member not found.');
        }

        $permissions = $staff->permissions()->pluck('permission')->toArray();

        return response()->json(['permissions' => $permissions]);
    }

    public function updatePermissions(Request $request, User $staff): JsonResponse
    {
        $restaurant = $this->resolveRestaurantForUser($request);

        $this->authorizeOwner($restaurant);

        if ($staff->restaurant_id !== $restaurant->id || $staff->role !== 'staff') {
            abort(404, 'Staff member not found.');
        }

        $data = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string'],
        ]);

        $allowed = $this->allowedPermissionsList();

        $incoming = array_filter(array_unique($data['permissions']), function ($p) use ($allowed) {
            return in_array($p, $allowed, true);
        });

        // Sync permissions
        $staff->permissions()->whereNotIn('permission', $incoming)->delete();

        $existing = $staff->permissions()->pluck('permission')->toArray();
        $toAdd = array_diff($incoming, $existing);

        $insert = [];
        foreach ($toAdd as $p) {
            $insert[] = ['user_id' => $staff->id, 'permission' => $p, 'created_at' => now(), 'updated_at' => now()];
        }

        if (count($insert) > 0) {
            UserPermission::insert($insert);
        }

        return response()->json(['message' => 'Permissions updated.']);
    }

    private function allowedPermissionsList(): array
    {
        return [
            'dashboard.view',
            'orders.view','orders.add','orders.update','orders.delete',
            'meals.view','meals.add','meals.update','meals.delete',
            'categories.view','categories.add','categories.update','categories.delete',
            'tables.view','tables.add','tables.update','tables.delete',
            'appearance.view','appearance.update','appearance.delete',
            'staff.view','staff.add','staff.update','staff.delete',
            'qrcode.view',
            'profile.view','profile.update',
        ];
    }
}
