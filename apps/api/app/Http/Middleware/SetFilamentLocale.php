<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetFilamentLocale
{
    public function handle(Request $request, Closure $next): mixed
    {
        $locale = app()->getLocale();

        app()->setLocale('id');

        try {
            return $next($request);
        } finally {
            app()->setLocale($locale);
        }
    }
}
