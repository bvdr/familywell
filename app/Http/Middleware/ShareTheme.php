<?php

namespace App\Http\Middleware;

use App\Support\Theming\ThemeTokens;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

class ShareTheme
{
    public function handle(Request $request, Closure $next)
    {
        // P1: always the default theme. Later: resolve the active family's chosen theme here.
        View::share('themeCss', ThemeTokens::css('default'));

        return $next($request);
    }
}
