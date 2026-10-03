<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentFamily;
use App\Support\Theming\ThemeTokens;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

class ShareTheme
{
    public function handle(Request $request, Closure $next)
    {
        // The member's skin (personal -> family -> built-in default), see ThemeTokens::resolve.
        View::share('themeCss', ThemeTokens::css(ThemeTokens::resolve($request->user(), CurrentFamily::id())));

        return $next($request);
    }
}
