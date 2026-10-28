<?php

namespace App\Support\Ai;

use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Every model call we pay for goes through here: Cloudflare Workers AI (Clef, Clairic, Llama) and Google Gemini.
 * With services.workers_ai.gateway set, calls run through Cloudflare AI Gateway ("familywell"), which logs each one with
 * its tokens, cost and latency, tagged (cf-aig-metadata) with the family, the user and the feature, so usage can be
 * followed per user in the Cloudflare dashboard. Without it (self-hosted) they go to the providers directly. Either
 * way each call also lands in our ai_usage ledger (AiUsage), which is what the AI points are counted from.
 *
 * Calls run in parallel (one Http::pool). A failed call returns null and is reported, never thrown: callers degrade.
 * The gateway is authenticated with its own token (AI Gateway Run only): the same token in both headers is refused
 * by the gateway (tested 2026-10-28), and it keeps the two permissions apart anyway.
 */
final class AiGateway
{
    public static function workersAi(): bool
    {
        return filled(config('services.workers_ai.account')) && filled(config('services.workers_ai.token'));
    }

    public static function gemini(): bool
    {
        return filled(config('services.gemini.key'));
    }

    /**
     * @param  array<string, array{0: 'workers-ai'|'google-ai-studio', 1: string, 2: array<string, mixed>}>  $calls
     *                                                                                                               key => [provider, model, payload], e.g. 'kinds' => ['workers-ai', 'cloudflare/clef', [...]]
     * @return array<string, array<string, mixed>|null> key => the provider's answer (Workers AI: `result`; Gemini: the whole body), null on failure
     */
    public static function run(string $feature, array $calls): array
    {
        $responses = Http::pool(fn (Pool $pool) => collect($calls)->map(function ($call, $key) use ($pool, $feature) {
            [$provider, $model, $payload] = $call;
            $request = $pool->as($key)->timeout(20)->withHeaders(self::gatewayHeaders($feature));

            return $provider === 'workers-ai'
                ? $request->withToken((string) config('services.workers_ai.token'))->post(self::url($provider, $model), $payload)
                : $request->withHeaders(['x-goog-api-key' => (string) config('services.gemini.key')])->post(self::url($provider, $model), $payload);
        })->all());

        $out = [];
        foreach ($calls as $key => [$provider, $model]) {
            $r = $responses[$key] ?? null;
            $ok = $r instanceof Response && $r->successful() && ($provider !== 'workers-ai' || $r->json('success'));
            if (! $ok) {
                report(new RuntimeException("AI call {$provider}/{$model} failed: ".($r instanceof Response ? $r->status().' '.mb_substr($r->body(), 0, 500) : 'no response')));
                $out[$key] = null;

                continue;
            }
            if ($provider === 'workers-ai') {
                // Cloudflare's own models report input_tokens; chat models (Llama) report prompt / completion tokens.
                AiUsage::record($feature, 'cloudflare', basename($model), (int) $r->json('result.usage.input_tokens', $r->json('result.usage.prompt_tokens', 0)), (int) $r->json('result.usage.completion_tokens', 0));
                $out[$key] = $r->json('result');
            } else {
                AiUsage::record($feature, 'gemini', $model, (int) $r->json('usageMetadata.promptTokenCount', 0), (int) $r->json('usageMetadata.candidatesTokenCount', 0));
                $out[$key] = $r->json();
            }
        }

        return $out;
    }

    private static function gateway(): ?string
    {
        $gw = config('services.workers_ai.gateway');

        return filled($gw) && filled(config('services.workers_ai.account')) && filled(config('services.workers_ai.gateway_token')) ? $gw : null;
    }

    private static function url(string $provider, string $model): string
    {
        $account = config('services.workers_ai.account');
        if ($gw = self::gateway()) {
            $base = "https://gateway.ai.cloudflare.com/v1/{$account}/{$gw}/{$provider}";

            return $provider === 'workers-ai' ? "{$base}/@cf/{$model}" : "{$base}/v1beta/models/{$model}:generateContent";
        }

        return $provider === 'workers-ai'
            ? "https://api.cloudflare.com/client/v4/accounts/{$account}/ai/run/@cf/{$model}"
            : "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
    }

    /** @return array<string, string> */
    private static function gatewayHeaders(string $feature): array
    {
        if (! self::gateway()) {
            return [];
        }

        // Up to five entries are kept per request (Cloudflare's limit); strings / numbers only.
        return [
            'cf-aig-authorization' => 'Bearer '.config('services.workers_ai.gateway_token'),
            'cf-aig-metadata' => json_encode(array_filter([
                'family' => CurrentFamily::id(),
                'user' => auth()->id(),
                'feature' => $feature,
                'env' => app()->environment(),
            ], fn ($v) => $v !== null)),
        ];
    }
}
