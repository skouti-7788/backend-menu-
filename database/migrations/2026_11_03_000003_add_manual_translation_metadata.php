<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_translations', function (Blueprint $table) {
            $table->boolean('is_manual')->default(false)->after('description');
            $table->string('source_hash', 64)->nullable()->after('is_manual');
        });

        Schema::table('menu_category_translations', function (Blueprint $table) {
            $table->boolean('is_manual')->default(false)->after('description');
            $table->string('source_hash', 64)->nullable()->after('is_manual');
        });

        Schema::table('restaurant_translations', function (Blueprint $table) {
            $table->boolean('is_manual')->default(false)->after('description');
            $table->string('source_hash', 64)->nullable()->after('is_manual');
        });
    }

    public function down(): void
    {
        Schema::table('meal_translations', function (Blueprint $table) {
            $table->dropColumn(['is_manual', 'source_hash']);
        });
        Schema::table('menu_category_translations', function (Blueprint $table) {
            $table->dropColumn(['is_manual', 'source_hash']);
        });
        Schema::table('restaurant_translations', function (Blueprint $table) {
            $table->dropColumn(['is_manual', 'source_hash']);
        });
    }
};
