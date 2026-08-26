<?php

namespace App\Http\Controllers\Api;

// use App\Http\Controllers\Controller;
// use App\Http\Requests\Restaurant\RestaurantRequest;
// use App\Http\Resources\RestaurantResource;
// use App\Models\Restaurant;
// use Illuminate\Http\JsonResponse;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Storage;

// class RestaurantController extends Controller
// {
//     public function index(Request $request)
//     {
//         $user = $request->user();

//         $restaurants = $user->role === 'admin'
//             ? Restaurant::query()
//             : Restaurant::where('user_id', $user->id);

//         return RestaurantResource::collection($restaurants->latest()->paginate(20));
//     }

//     public function store(RestaurantRequest $request): RestaurantResource
//     {
//         $data = $request->safe()->except(['logo', 'cover_image']);
//         $data['user_id'] = $request->user()->id;

//         if ($request->hasFile('logo')) {
//             $data['logo'] = $request->file('logo')->store('restaurants/logos', 'public');
//         }

//         if ($request->hasFile('cover_image')) {
//             $data['cover_image'] = $request->file('cover_image')->store('restaurants/covers', 'public');
//         }

//         $restaurant = Restaurant::create($data);

//         return new RestaurantResource($restaurant);
//     }

//     public function show(Restaurant $restaurant): RestaurantResource
//     {
//         $this->authorizeRestaurant($restaurant);

//         return new RestaurantResource($restaurant);
//     }

//     public function update(RestaurantRequest $request, Restaurant $restaurant): RestaurantResource
//     {
//         $this->authorizeRestaurant($restaurant);

//         $data = $request->safe()->except(['logo', 'cover_image']);

//         if ($request->hasFile('logo')) {
//             $this->deleteFile($restaurant->logo);
//             $data['logo'] = $request->file('logo')->store('restaurants/logos', 'public');
//         }

//         if ($request->hasFile('cover_image')) {
//             $this->deleteFile($restaurant->cover_image);
//             $data['cover_image'] = $request->file('cover_image')->store('restaurants/covers', 'public');
//         }

//         $restaurant->update($data);

//         return new RestaurantResource($restaurant);
//     }

//     public function destroy(Restaurant $restaurant): JsonResponse
//     {
//         $this->authorizeRestaurant($restaurant);

//         $this->deleteFile($restaurant->logo);
//         $this->deleteFile($restaurant->cover_image);
//         $restaurant->delete();

//         return response()->json(['message' => 'Restaurant deleted successfully.']);
//     }

//     protected function authorizeRestaurant(Restaurant $restaurant): void
//     {
//         $user = auth()->user();

//         if ($user->role !== 'admin' && $restaurant->user_id !== $user->id) {
//             abort(403, 'You are not authorized to manage this restaurant.');
//         }
//     }

//     protected function deleteFile(?string $path): void
//     {
//         if ($path && Storage::disk('public')->exists($path)) {
//             Storage::disk('public')->delete($path);
//         }
//     }
// }
 

 
use App\Http\Controllers\Controller;
use App\Http\Requests\Restaurant\RestaurantRequest;
use App\Http\Resources\RestaurantResource;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class RestaurantController extends Controller
{
    /**
     * Admin: جميع المطاعم لجميع المستخدمين.
     * Manager: قائمة مطاعمه هو فقط (قد تكون فارغة).
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $restaurants = $user->isAdmin()
            ? Restaurant::query()
            : $user->restaurants();

        return RestaurantResource::collection(
            $restaurants->latest()->paginate(20)
        );
    }

    /**
     * إنشاء مطعم جديد يتبع للمستخدم الحالي.
     * مسموح بأكثر من مطعم لنفس المستخدم.
     */
    public function store(RestaurantRequest $request): RestaurantResource
    {
        $data = $request->safe()->except(['logo', 'cover_image']);
        $data['user_id'] = $request->user()->id;

        if ($request->hasFile('logo')) {
            $data['logo'] = $request->file('logo')->store('restaurants/logos', 'public');
        }

        if ($request->hasFile('cover_image')) {
            $data['cover_image'] = $request->file('cover_image')->store('restaurants/covers', 'public');
        }

        $restaurant = Restaurant::create($data);

        return new RestaurantResource($restaurant);
    }

    public function show(Restaurant $restaurant): RestaurantResource
    {
        $this->authorizeRestaurant($restaurant);

        return new RestaurantResource($restaurant);
    }

    public function update(RestaurantRequest $request, Restaurant $restaurant): RestaurantResource
    {
        $this->authorizeRestaurant($restaurant);

        $data = $request->safe()->except(['logo', 'cover_image']);

        if ($request->hasFile('logo')) {
            $this->deleteFile($restaurant->logo);
            $data['logo'] = $request->file('logo')->store('restaurants/logos', 'public');
        }

        if ($request->hasFile('cover_image')) {
            $this->deleteFile($restaurant->cover_image);
            $data['cover_image'] = $request->file('cover_image')->store('restaurants/covers', 'public');
        }

        $restaurant->update($data);

        return new RestaurantResource($restaurant);
    }

    public function destroy(Restaurant $restaurant): JsonResponse
    {
        $this->authorizeRestaurant($restaurant);

        $this->deleteFile($restaurant->logo);
        $this->deleteFile($restaurant->cover_image);
        $restaurant->delete();

        return response()->json(['message' => 'Restaurant deleted successfully.']);
    }

    protected function authorizeRestaurant(Restaurant $restaurant): void
    {
        $user = auth()->user();

        if (! $user->isAdmin() && $restaurant->user_id !== $user->id) {
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