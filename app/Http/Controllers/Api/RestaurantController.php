<?php

namespace App\Http\Controllers\Api;

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
     * Get restaurants available to current user.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        /*
         * ADMIN
         *
         * Can see all restaurants.
         */
        if ($user->isAdmin()) {
            $restaurants =
                Restaurant::query();
        }

        /*
         * STAFF
         *
         * Can see only their restaurant.
         */
        elseif (
            $user->isStaff() &&
            $user->restaurant_id
        ) {
            $restaurants =
                Restaurant::where(
                    'id',
                    $user->restaurant_id
                );
        }

        /*
         * OWNER / MANAGER
         *
         * Can see restaurants they own.
         */
        else {
            $restaurants =
                $user->restaurants();
        }

        return RestaurantResource::collection(
            $restaurants
                ->latest()
                ->paginate(20)
        );
    }

    /**
     * Create restaurant.
     */
    public function store(
        RestaurantRequest $request
    ): RestaurantResource {
        $data = $request
            ->safe()
            ->except([
                'logo',
                'cover_image'
            ]);

        $data['user_id'] =
            $request->user()->id;

        if ($request->hasFile('logo')) {
            $data['logo'] =
                $request
                    ->file('logo')
                    ->store(
                        'restaurants/logos',
                        'public'
                    );
        }

        if (
            $request->hasFile(
                'cover_image'
            )
        ) {
            $data['cover_image'] =
                $request
                    ->file('cover_image')
                    ->store(
                        'restaurants/covers',
                        'public'
                    );
        }

        $restaurant =
            Restaurant::create($data);

        return new RestaurantResource(
            $restaurant
        );
    }

    /**
     * Show restaurant.
     */
    public function show(
        Restaurant $restaurant
    ): RestaurantResource {
        $this->requireRestaurantAccess(
            $restaurant
        );

        return new RestaurantResource(
            $restaurant
        );
    }

    /**
     * Update restaurant.
     */
    public function update(
        RestaurantRequest $request,
        Restaurant $restaurant
    ): RestaurantResource {
        $this->requireRestaurantAccess(
            $restaurant
        );

        $data = $request
            ->safe()
            ->except([
                'logo',
                'cover_image'
            ]);

        if ($request->hasFile('logo')) {
            $this->deleteFile(
                $restaurant->logo
            );

            $data['logo'] =
                $request
                    ->file('logo')
                    ->store(
                        'restaurants/logos',
                        'public'
                    );
        }

        if (
            $request->hasFile(
                'cover_image'
            )
        ) {
            $this->deleteFile(
                $restaurant->cover_image
            );

            $data['cover_image'] =
                $request
                    ->file('cover_image')
                    ->store(
                        'restaurants/covers',
                        'public'
                    );
        }

        $restaurant->update($data);

        return new RestaurantResource(
            $restaurant->refresh()
        );
    }

    /**
     * Delete restaurant.
     */
    public function destroy(
        Restaurant $restaurant
    ): JsonResponse {
        $this->requireRestaurantAccess(
            $restaurant
        );

        $this->deleteFile(
            $restaurant->logo
        );

        $this->deleteFile(
            $restaurant->cover_image
        );

        $restaurant->delete();

        return response()->json([
            'message' =>
                'Restaurant deleted successfully.'
        ]);
    }

    /**
     * Check whether current user
     * can access this restaurant.
     */
    protected function requireRestaurantAccess(
        Restaurant $restaurant
    ): void {
        $user = auth()->user();

        if (! $user) {
            abort(
                401,
                'Unauthenticated.'
            );
        }

        /*
         * ADMIN
         */
        if ($user->isAdmin()) {
            return;
        }

        /*
         * OWNER / MANAGER
         *
         * Restaurant owner.
         */
        if (
            (int) $restaurant->user_id ===
            (int) $user->id
        ) {
            return;
        }

        /*
         * STAFF
         *
         * Staff can access only
         * their assigned restaurant.
         */
        if (
            $user->isStaff() &&
            (int) $user->restaurant_id ===
            (int) $restaurant->id
        ) {
            return;
        }

        abort(
            403,
            'You are not authorized to access this restaurant.'
        );
    }

    /**
     * Delete stored file.
     */
    protected function deleteFile(
        ?string $path
    ): void {
        if (
            $path &&
            Storage::disk('public')->exists($path)
        ) {
            Storage::disk('public')->delete(
                $path
            );
        }
    }
}