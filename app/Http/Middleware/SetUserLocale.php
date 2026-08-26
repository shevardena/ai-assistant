<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetUserLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale;
        $supportedLocales = config('locales.supported', []);

        app()->setLocale(
            is_string($locale) && array_key_exists($locale, $supportedLocales)
                ? $locale
                : (string) config('locales.default', 'en'),
        );

        return $next($request);
    }
}
