<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetApiLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $requested = strtolower(str_replace('_', '-', (string) $request->header('Accept-Language', 'en')));
        $locale = match (true) {
            str_starts_with($requested, 'ar') => 'ar',
            str_starts_with($requested, 'fr') => 'fr',
            default => 'en',
        };

        App::setLocale($locale);

        return $next($request);
    }
}
