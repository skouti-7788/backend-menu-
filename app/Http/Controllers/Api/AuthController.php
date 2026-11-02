<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
// use App\Models\Restaurant;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        // Create the account and restaurant atomically to avoid orphan users.
        [$user, $restaurant] = DB::transaction(function () use ($request) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => $request->password,
                'role' => 'owner',
            ]);

            $restaurant = $user->restaurants()->create([
                'name' => $request->name,
                'email' => $request->email,
                'address' => $request->address,
                'phone' => $request->phone,
            ]);

            $user->restaurant_id = $restaurant->id;
            $user->save();

            return [$user, $restaurant];
        });

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'restaurant_id' => $user->restaurant_id,
            ],
            'token' => $token,
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }
        // Keep sessions on other devices; logout revokes only the current token.
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'restaurant_id' => $user->restaurant_id ?? $user->restaurants()->first()?->id,
                'permissions' => $user->role === 'owner' ? [] : $user->permissions()->pluck('permission')->toArray(),
            ],
            'token' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    public function listUsers(): JsonResponse
    {
        $users = User::orderBy('created_at', 'desc')
            ->withCount('restaurants')
            ->get(['id', 'name', 'email', 'role', 'created_at']);

        return response()->json(['users' => $users]);
    }
}