<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_category_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_category_id')->constrained('menu_categories')->cascadeOnDelete();
            $table->string('language', 10);
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['menu_category_id', 'language']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_category_translations');
    }
};
