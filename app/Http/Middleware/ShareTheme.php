<?php

namespace App\Http\Middleware;

use App\Models\Family;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Theming\ThemeTokens;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

class ShareTheme
{
    public function handle(Request $request, Closure $next)
    {
        // Resolve the active skin: the member's personal choice wins, else the active family's default,
        // else the built-in default (minimal-mono). Only query the family when the user hasn't set a
        // personal skin, so most requests do no extra query. A null theme means "inherit" -> the
        // built-in default; ThemeTokens::css also falls back to it for an unknown name.
        $user = $request->user();
        $theme = $user?->theme;
        if (! $theme && ($familyId = CurrentFamily::id())) {
            $theme = Family::find($familyId)?->theme;
        }

        View::share('themeCss', ThemeTokens::css($theme ?: 'minimal-mono'));

        return $next($request);
    }
}
