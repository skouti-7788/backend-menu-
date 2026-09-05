<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('restaurant_appearances', function (Blueprint $table) {
    $table->id();

    $table->foreignId('restaurant_id')
        ->constrained()
        ->cascadeOnDelete();

    $table->string('logo')->nullable();
    $table->string('background_image')->nullable();
    $table->string('header_image')->nullable();

    $table->string('primary_color')->default('#D97706');
    $table->string('secondary_color')->default('#92400E');
    $table->string('text_color')->default('#1F2937');
    $table->string('background_color')->default('#FFFFFF');

    $table->string('font_family')->default('Inter');

    $table->timestamps();

    $table->unique('restaurant_id');
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurant_appearances');
    }
};
