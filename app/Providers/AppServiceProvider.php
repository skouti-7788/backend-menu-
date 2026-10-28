<?php

namespace App\Providers;

use App\Mail\Transport\HubFlyTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Mail::extend('hubfly', function (array $config = []) {
            return new HubFlyTransport(
                (string) config('services.hubfly.key', '')
            );
        });

        ResetPassword::createUrlUsing(function ($user, string $token) {
            $query = http_build_query([
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ]);

            return rtrim(config('app.frontend_url'), '/')
                . '/reset-password?'
                . $query;
        });
    }
}
