<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'restaurant_id')) {
                $table->foreignId('restaurant_id')->nullable()->constrained('restaurants')->nullOnDelete()->after('id');
            }
        });

        // Backfill existing owner relationship: if a restaurant exists with user_id, set user's restaurant_id
        if (Schema::hasTable('restaurants')) {
            \Illuminate\Support\Facades\DB::statement(
                'UPDATE users u JOIN restaurants r ON r.user_id = u.id SET u.restaurant_id = r.id WHERE u.restaurant_id IS NULL'
            );
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'restaurant_id')) {
                $table->dropForeign(['restaurant_id']);
                $table->dropColumn('restaurant_id');
            }
        });
    }
};
