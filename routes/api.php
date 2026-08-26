<?php

use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MealController;
use App\Http\Controllers\Api\MenuCategoryController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\RestaurantController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\RestaurantTableController;
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

// Current authenticated user
Route::middleware('auth:sanctum')->get('user', function (Request $request) {
    return response()->json(['user' => $request->user()]);
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::apiResource('restaurants', RestaurantController::class);
    // Route::apiResource('tables', RestaurantTableController::class);
    Route::delete('/restaurants/{restaurant}/tables/all', [RestaurantTableController::class, 'destroyAll']);
    Route::apiResource('restaurants.tables', RestaurantTableController::class);
    Route::prefix('restaurants/{restaurant}')->group(function () {
        Route::apiResource('categories', MenuCategoryController::class)->shallow();
        Route::apiResource('meals', MealController::class)->shallow();
        Route::apiResource('orders', OrderController::class)->shallow();
    });

    Route::put('orders/{order}/status', [OrderController::class, 'updateStatus']);
    Route::delete('orders/{order}/delete', [OrderController::class, 'destroy']);
    Route::get('analytics/restaurants/{restaurant}', [AnalyticsController::class, 'restaurantOverview']);
    Route::get('analytics/restaurants/{restaurant}/popular-meals', [AnalyticsController::class, 'popularMeals']);
    Route::get('analytics/restaurants/{restaurant}/menu-views', [AnalyticsController::class, 'menuViews']);
   
    Route::middleware([EnsureUserHasRole::class.':admin'])->group(function () {
        Route::get('admin/users', [AuthController::class, 'listUsers']);
    });
});

Route::prefix('menu')->group(function () {
    Route::get('{slug}', [MenuController::class, 'show']);
    Route::post('{slug}/view', [MenuController::class, 'recordView']);
    Route::post('{slug}/orders', [MenuController::class, 'storeOrder']);

});
