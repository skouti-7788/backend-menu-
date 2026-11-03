<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_subscriptions', function (Blueprint $table) {
            $table->boolean('cancel_at_period_end')->default(false)->after('ends_at');
            $table->timestamp('cancelled_at')->nullable()->after('cancel_at_period_end');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['cancel_at_period_end', 'cancelled_at']);
        });
    }
};
