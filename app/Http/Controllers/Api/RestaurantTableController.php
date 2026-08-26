<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RestaurantTableController extends Controller
{
    /**
     * Get all tables of a restaurant.
     */
    public function index(Restaurant $restaurant)
    {
        $this->authorizeRestaurant($restaurant);

        $tables = $restaurant->tables()
            ->orderBy('number')
            ->get();

        return response()->json($tables);
    }

    /**
     * Create a new table.
     */
    public function store(Request $request, Restaurant $restaurant)
    {
        $this->authorizeRestaurant($restaurant);

        $validated = $request->validate([
            'number' => [
                'required',
                'integer',
                'min:1',
                'unique:restaurant_tables,number,NULL,id,restaurant_id,' . $restaurant->id,
            ],
            'name' => [
                'nullable',
                'string',
                'max:255',
            ],
        ]);

        $table = $restaurant->tables()->create([
            'number' => $validated['number'],
            'name' => $validated['name'] ?? 'Table ' . $validated['number'],
            'status' => 'available',
        ]);

        return response()->json([
            'message' => 'Table created successfully.',
            'table' => $table,
        ], 201);
    }

    /**
     * Create multiple tables at once (bulk create).
     */
    public function bulkStore(Request $request, Restaurant $restaurant)
    {
        $this->authorizeRestaurant($restaurant);

        $validated = $request->validate([
            'count' => [
                'required',
                'integer',
                'min:1',
                'max:200',
            ],
        ]);

        $count = $validated['count'];

        // نلقاو آخر رقم كاين فهاد الريستورا باش نبداو من بعدو
        $lastNumber = $restaurant->tables()->max('number') ?? 0;

        $tables = [];

        DB::transaction(function () use ($restaurant, $count, $lastNumber, &$tables) {
            for ($i = 1; $i <= $count; $i++) {
                $number = $lastNumber + $i;

                $tables[] = $restaurant->tables()->create([
                    'number' => $number,
                    'name' => 'Table ' . $number,
                    'status' => 'available',
                ]);
            }
        });

        return response()->json([
            'message' => 'Tables created successfully.',
            'tables' => $tables,
        ], 201);
    }

    /**
     * Show one table.
     */
    public function show(Restaurant $restaurant, RestaurantTable $table)
    {
        $this->authorizeRestaurant($restaurant);
        $this->authorizeTable($restaurant, $table);

        return response()->json($table);
    }

    /**
     * Update table.
     */
    public function update(
        Request $request,
        Restaurant $restaurant,
        RestaurantTable $table
    ) {
        $this->authorizeRestaurant($restaurant);
        $this->authorizeTable($restaurant, $table);

        $validated = $request->validate([
            'number' => [
                'required',
                'integer',
                'min:1',
                'unique:restaurant_tables,number,' . $table->id . ',id,restaurant_id,' . $restaurant->id,
            ],
            'name' => [
                'nullable',
                'string',
                'max:255',
            ],
            'status' => [
                'required',
                'in:available,occupied,reserved',
            ],
        ]);

        $table->update([
            'number' => $validated['number'],
            'name' => $validated['name'] ?? 'Table ' . $validated['number'],
            'status' => $validated['status'],
        ]);

        return response()->json([
            'message' => 'Table updated successfully.',
            'table' => $table->fresh(),
        ]);
    }

    /**
     * Delete table.
     */
    public function destroy(Restaurant $restaurant, RestaurantTable $table)
    {
        $this->authorizeRestaurant($restaurant);
        $this->authorizeTable($restaurant, $table);

        $table->delete();

        return response()->json([
            'message' => 'Table deleted successfully.',
        ]);
    }

    /**
     * Make sure restaurant belongs to authenticated user.
     */
    private function authorizeRestaurant(Restaurant $restaurant): void
    {
        if ($restaurant->user_id !== Auth::id()) {
            abort(403, 'Unauthorized restaurant.');
        }
    }

    /**
     * Make sure table belongs to this restaurant.
     */
    private function authorizeTable(
        Restaurant $restaurant,
        RestaurantTable $table
    ): void {
        if ($table->restaurant_id !== $restaurant->id) {
            abort(403, 'This table does not belong to this restaurant.');
        }
    }

    public function destroyAll(Restaurant $restaurant)
    {
        $this->authorizeRestaurant($restaurant);
 
        $count = $restaurant->tables()->count();
 
        $restaurant->tables()->delete();
 
        return response()->json([
            'message' => 'All tables deleted successfully.',
            'deleted_count' => $count,
        ]);
    }
}