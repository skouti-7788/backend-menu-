<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantTable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RestaurantTableController extends Controller
{
    /**
     * Get all tables of a restaurant.
     */
    public function index(Restaurant $restaurant)
    {
        $this->requirePermission(
            $restaurant,
            'tables.view'
        );

        $tables = $restaurant->tables()
            ->orderBy('number')
            ->get();

        return response()->json($tables);
    }

    /**
     * Create a new table.
     */
    public function store(
        Request $request,
        Restaurant $restaurant
    ) {
        $this->requirePermission(
            $restaurant,
            'tables.add'
        );

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
            'name' => $validated['name']
                ?? 'Table ' . $validated['number'],
            'status' => 'available',
        ]);

        return response()->json([
            'message' => 'Table created successfully.',
            'table' => $table,
        ], 201);
    }

    /**
     * Create multiple tables at once.
     */
    public function bulkStore(
        Request $request,
        Restaurant $restaurant
    ) {
        $this->requirePermission(
            $restaurant,
            'tables.add'
        );

        $validated = $request->validate([
            'count' => [
                'required',
                'integer',
                'min:1',
                'max:200',
            ],
        ]);

        $count = $validated['count'];

        /*
        |--------------------------------------------------------------------------
        | Get the current highest table number.
        |--------------------------------------------------------------------------
        */

        $lastNumber = $restaurant->tables()->max('number') ?? 0;

        $tables = [];

        DB::transaction(function () use (
            $restaurant,
            $count,
            $lastNumber,
            &$tables
        ) {
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
    public function show(
        Restaurant $restaurant,
        RestaurantTable $table
    ) {
        $this->requirePermission(
            $restaurant,
            'tables.view'
        );

        $this->authorizeTable(
            $restaurant,
            $table
        );

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
        $this->requirePermission(
            $restaurant,
            'tables.update'
        );

        $this->authorizeTable(
            $restaurant,
            $table
        );

        $validated = $request->validate([
            'number' => [
                'required',
                'integer',
                'min:1',
                'unique:restaurant_tables,number,'
                    . $table->id
                    . ',id,restaurant_id,'
                    . $restaurant->id,
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
            'name' => $validated['name']
                ?? 'Table ' . $validated['number'],
            'status' => $validated['status'],
        ]);

        return response()->json([
            'message' => 'Table updated successfully.',
            'table' => $table->fresh(),
        ]);
    }

    /**
     * Delete one table.
     */
    public function destroy(
        Restaurant $restaurant,
        RestaurantTable $table
    ) {
        $this->requirePermission(
            $restaurant,
            'tables.delete'
        );

        $this->authorizeTable(
            $restaurant,
            $table
        );

        $table->delete();

        return response()->json([
            'message' => 'Table deleted successfully.',
        ]);
    }

    /**
     * Delete all tables of a restaurant.
     */
    public function destroyAll(
        Restaurant $restaurant
    ) {
        /*
        |--------------------------------------------------------------------------
        | IMPORTANT:
        | Use the same permission system as the other table actions.
        |--------------------------------------------------------------------------
        */

        $this->requirePermission(
            $restaurant,
            'tables.delete'
        );

        $count = $restaurant
            ->tables()
            ->count();

        $restaurant
            ->tables()
            ->delete();

        return response()->json([
            'message' => 'All tables deleted successfully.',
            'deleted_count' => $count,
        ]);
    }

    /**
     * Make sure the table belongs to this restaurant.
     */
    private function authorizeTable(
        Restaurant $restaurant,
        RestaurantTable $table
    ): void {
        if ((int) $table->restaurant_id !== (int) $restaurant->id) {
            abort(
                403,
                'This table does not belong to this restaurant.'
            );
        }
    }
}
 