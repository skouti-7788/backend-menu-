<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('language', 10);
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['restaurant_id', 'language']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_translations');
    }
};
