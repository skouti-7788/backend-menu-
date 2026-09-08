<?php

namespace App\Http\Controllers\Api;

// use App\Http\Controllers\Controller;
// use App\Http\Requests\Auth\LoginRequest;
// use App\Http\Requests\Auth\RegisterRequest;
// use App\Models\User;
// use Illuminate\Http\JsonResponse;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Hash;

// class AuthController extends Controller
// {
//     public function register(RegisterRequest $request): JsonResponse
//     {
//         $user = User::create([
//             'name' => $request->name,
//             'email' => $request->email,
//             'password' => $request->password,
//             'role' => $request->role ?? 'restaurant_manager',
//         ]);

//         $token = $user->createToken('api-token')->plainTextToken;

//         return response()->json([
//             'user' => $user,
//             'token' => $token,
//         ], 201);
//     }

//     public function login(LoginRequest $request): JsonResponse
//     {
//         $user = User::where('email', $request->email)->first();

//         if (! $user || ! Hash::check($request->password, $user->password)) {
//             return response()->json(['message' => 'Invalid credentials.'], 401);
//         }

//         $user->tokens()->delete();
//         $token = $user->createToken('api-token')->plainTextToken;

//         return response()->json([
//             'user' => $user,
//             'token' => $token,
//         ]);
//     }

//     public function logout(Request $request): JsonResponse
//     {
//         $request->user()?->currentAccessToken()?->delete();

//         return response()->json(['message' => 'Logged out successfully.']);
//     }

//     public function listUsers(): JsonResponse
//     {
//         $users = User::orderBy('created_at', 'desc')->get(['id', 'name', 'email', 'role', 'created_at']);

//         return response()->json(['users' => $users]);
//     }
// }
 

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
// use App\Models\Restaurant;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        // Force role to owner for registration
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
            'role' => 'owner',
        ]);

        // Create restaurant owned by this user
        $restaurant = $user->restaurants()->create([
            'name' => $request->name,
            'email' => $request->email,
            'address' => $request->address,
            'phone' => $request->phone,
        ]);

        // Set explicit restaurant_id on user (for tenant scoping)
        $user->restaurant_id = $restaurant->id;
        $user->save();

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

        $user->tokens()->delete();
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