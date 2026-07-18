<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        {{-- DS typography: Playfair Display (headings/brand), Inter (body/UI), Geist Mono (figures, times).
             Without these the app fell back to system serif/sans/mono and lost its brand voice. --}}
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|playfair-display:400,500,600,700|geist-mono:400,500,600" rel="stylesheet" />

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
