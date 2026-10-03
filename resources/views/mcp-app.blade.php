{{-- An MCP Apps widget page (see App\Mcp\Apps\Widget). Same shell as laravel/mcp's x-mcp::app, but with the
     SDK (createMcpApp, the postMessage bridge to the host) passed in, so Widget::sdk() can declare display
     modes. Everything is inlined (no CSP domains). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <script>{!! $sdk !!}</script>
    <style>{!! $css !!}</style>
</head>
<body>
    <div id="app"><p class="muted pad">Loading…</p></div>
    <script>{!! $js !!}</script>
</body>
</html>
