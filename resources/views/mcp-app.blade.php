{{-- An MCP Apps widget page (see App\Mcp\Apps\Widget). x-mcp::app is laravel/mcp's shell: it inlines the
     postMessage SDK (createMcpApp) that talks to the host. Everything else is inlined too (no CSP domains). --}}
<x-mcp::app :title="$title">
    <x-slot:head>
        <style>{!! $css !!}</style>
    </x-slot:head>
    <div id="app"><p class="muted pad">Loading…</p></div>
    <script>{!! $js !!}</script>
</x-mcp::app>
