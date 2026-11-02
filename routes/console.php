<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sanctum:prune-expired')->daily();

Schedule::call(function (): void {
    DB::table('restaurant_subscriptions')
        ->where('status', 'active')
        ->whereNotNull('ends_at')
        ->where('ends_at', '<=', now())
        ->update([
            'status' => 'expired',
            'updated_at' => now(),
        ]);
})->name('expire-ended-restaurant-subscriptions')->everyFiveMinutes()->withoutOverlapping();

