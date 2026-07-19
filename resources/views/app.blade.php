<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        {{-- viewport-fit=cover lets the standalone PWA paint under the notch; the tab bar uses the safe-area inset.
             interactive-widget=resizes-content makes the keyboard shrink the layout viewport (iOS 17+), so a
             bottom-anchored drawer sits flush above it without any visualViewport math. --}}
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        {{-- Installable PWA: added to the home screen it launches full-screen (no browser chrome).
             iOS reads the apple-* tags; Android/Chrome read the manifest. --}}
        <link rel="manifest" href="/manifest.webmanifest">
        <meta name="theme-color" content="#2D5E3A">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="FamilyWell">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        {{-- Brand fonts (Playfair, Inter, Geist Mono) are bundled via @fontsource in app.tsx; no CDN. --}}

        <style id="theme">{!! $themeCss ?? '' !!}</style>
        <script>window.__familywellModules = @json($moduleFrontends ?? []);</script>
        <script>
            window.__familywellReverb = @json($reverb ?? null);
            window.__familywellFamilyId = @json($activeFamilyId ?? null);
        </script>

        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
