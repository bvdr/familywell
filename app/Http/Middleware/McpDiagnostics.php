<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * TEMPORARY (2026-10-04): diagnose ChatGPT's "Failed to fetch template" for our MCP Apps widgets.
 * Logs one line per /mcp request: JSON-RPC method, tool name / resource uri, client, HTTP status, and for
 * resources/* what we answered (uri, mimeType, _meta, size) or the JSON-RPC error. No tool arguments or
 * results are logged (family data). Remove once the widget issue is fixed.
 */
class McpDiagnostics
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $method = $request->input('method');
            $line = [
                'method' => $method,
                'id' => $request->input('id'),
                'tool' => $method === 'tools/call' ? $request->input('params.name') : null,
                'uri' => $request->input('params.uri'),
                'client' => $request->input('params.clientInfo'),
                'client_caps' => $method === 'initialize' ? $request->input('params.capabilities') : null,
                'ua' => substr((string) $request->userAgent(), 0, 120),
                'accept' => $request->header('Accept'),
                'session' => $request->header('Mcp-Session-Id'),
                'status' => $response->getStatusCode(),
                'ctype' => $response->headers->get('Content-Type'),
            ];

            if (is_string($method) && str_starts_with($method, 'resources/')) {
                $body = (string) $response->getContent();
                // Streamed (SSE) answers wrap the JSON in "data: ..." lines.
                if (preg_match('/^data: (.+)$/m', $body, $m)) {
                    $body = $m[1];
                }
                $json = json_decode($body, true);
                $line['error'] = $json['error'] ?? null;
                $line['result'] = collect($json['result']['contents'] ?? $json['result']['resources'] ?? [])
                    ->map(fn ($c) => [
                        'uri' => $c['uri'] ?? null,
                        'mimeType' => $c['mimeType'] ?? null,
                        '_meta' => $c['_meta'] ?? null,
                        'size' => isset($c['text']) ? strlen($c['text']) : null,
                    ])->take(15)->values()->all();
                $line['raw_head'] = $json === null ? substr($body, 0, 300) : null;
            }

            Log::info('mcp-diag', array_filter($line, fn ($v) => $v !== null && $v !== []));
        } catch (\Throwable $e) {
            Log::warning('mcp-diag failed', ['error' => $e->getMessage()]);
        }

        return $response;
    }
}
