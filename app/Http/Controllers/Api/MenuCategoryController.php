<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MenuCategory\MenuCategoryRequest;
use App\Http\Resources\MenuCategoryResource;
use App\Models\MenuCategory;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MenuCategoryController extends Controller
{
    public function index(Request $request, Restaurant $restaurant)
    {
        $this->authorizeRestaurant($restaurant);

        return MenuCategoryResource::collection($restaurant->categories()->latest()->get());
    }

    public function store(MenuCategoryRequest $request, Restaurant $restaurant): MenuCategoryResource
    {
        $this->authorizeRestaurant($restaurant);

        $data = $request->safe()->except(['image']);
        $data['restaurant_id'] = $restaurant->id;
        $data['description'] = $request->description;
        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('categories', 'public');
        }
       
        $category = MenuCategory::create($data);
            // dd( $request);
        return new MenuCategoryResource($category);
    }

    // public function show(MenuCategory $category): MenuCategoryResource
    // {
    //     $this->authorizeRestaurant($category->restaurant);

    //     return new MenuCategoryResource($category);
    // }

    // public function update(MenuCategoryRequest $request, MenuCategory $category): MenuCategoryResource
    // {
    //     $this->authorizeRestaurant($category->restaurant);

    //     $data = $request->safe()->except(['image']);

    //     if ($request->hasFile('image')) {
    //         $this->deleteFile($category->image);
    //         $data['image'] = $request->file('image')->store('categories', 'public');
    //     }

    //     $category->update($data);

    //     return new MenuCategoryResource($category);
    // }

    // public function destroy(MenuCategory $category): JsonResponse
    // {
    //     $this->authorizeRestaurant($category->restaurant);

    //     $this->deleteFile($category->image);
    //     $category->delete();

    //     return response()->json(['message' => 'Menu category removed successfully.']);
    // }
        public function show(string $restaurant, string $category): MenuCategoryResource
    {
        $restaurantModel = Restaurant::findOrFail($restaurant);

        $this->authorizeRestaurant($restaurantModel);

        $menuCategory = MenuCategory::where('id', $category)
            ->where('restaurant_id', $restaurantModel->id)
            ->firstOrFail();

        return new MenuCategoryResource($menuCategory);
    }


    public function update(
        MenuCategoryRequest $request,
        string $restaurant,
        string $category
    ): MenuCategoryResource {
        $restaurantModel = Restaurant::findOrFail($restaurant);

        $this->authorizeRestaurant($restaurantModel);

        $menuCategory = MenuCategory::where('id', $category)
            ->where('restaurant_id', $restaurantModel->id)
            ->firstOrFail();

        $data = $request->safe()->except(['image']);

        if ($request->hasFile('image')) {
            $this->deleteFile($menuCategory->image);

            $data['image'] = $request->file('image')
                ->store('categories', 'public');
        }

        $menuCategory->update($data);

        return new MenuCategoryResource($menuCategory->refresh());
    }


    public function destroy(
        string $restaurant,
        string $category
    ): JsonResponse {
        $restaurantModel = Restaurant::findOrFail($restaurant);

        $this->authorizeRestaurant($restaurantModel);

        $menuCategory = MenuCategory::where('id', $category)
            ->where('restaurant_id', $restaurantModel->id)
            ->firstOrFail();

        $this->deleteFile($menuCategory->image);

        $menuCategory->delete();

        return response()->json([
            'message' => 'Menu category removed successfully.'
        ]);
    }
    protected function authorizeRestaurant(Restaurant $restaurant): void
    {
        $user = auth()->user();

        if ($user->role !== 'admin' && $restaurant->user_id !== $user->id) {
            abort(403, 'You are not authorized to manage this restaurant.');
        }
    }

    protected function deleteFile(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
