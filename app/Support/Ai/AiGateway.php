<?php

namespace App\Support\Ai;

use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The app's own Gemini calls (receipt scans; "Just say it" reads in the separate reader worker). Two ways in:
 *  - hosted: through Cloudflare AI Gateway (services.ai_gateway), which logs each call with tokens, cost and latency,
 *    tagged (cf-aig-metadata) with family / user / feature. With services.gemini.via_gateway the Gemini key is stored
 *    in the gateway itself (BYOK) and this app never holds it;
 *  - self-hosted: straight to Google with the install's own GEMINI_API_KEY.
 * Each call also lands in our ai_usage ledger (AiUsage), which the AI points are counted from. A failed call returns
 * null and is reported, never thrown: callers degrade.
 */
final class AiGateway
{
    public static function gemini(): bool
    {
        return filled(config('services.gemini.key')) || (self::gateway() !== null && config('services.gemini.via_gateway'));
    }

    /**
     * @param  array<string, array{0: string, 1: array<string, mixed>}>  $calls  key => [model, generateContent body]
     * @return array<string, array<string, mixed>|null> key => Gemini's answer body, null on failure
     */
    public static function run(string $feature, array $calls): array
    {
        $responses = Http::pool(fn (Pool $pool) => collect($calls)->map(function ($call, $key) use ($pool, $feature) {
            [$model, $payload] = $call;
            $request = $pool->as($key)->timeout(30)->withHeaders(self::headers($feature));

            return $request->post(self::url($model), $payload);
        })->all());

        $out = [];
        foreach ($calls as $key => [$model]) {
            $r = $responses[$key] ?? null;
            if (! $r instanceof Response || ! $r->successful()) {
                report(new RuntimeException("Gemini {$model} failed: ".($r instanceof Response ? $r->status().' '.mb_substr($r->body(), 0, 500) : 'no response')));
                $out[$key] = null;

                continue;
            }
            AiUsage::record($feature, 'gemini', $model, (int) $r->json('usageMetadata.promptTokenCount', 0), (int) $r->json('usageMetadata.candidatesTokenCount', 0));
            $out[$key] = $r->json();
        }

        return $out;
    }

    private static function gateway(): ?string
    {
        $c = config('services.ai_gateway');

        return filled($c['id'] ?? null) && filled($c['account'] ?? null) && filled($c['token'] ?? null) ? $c['id'] : null;
    }

    private static function url(string $model): string
    {
        return ($gw = self::gateway())
            ? 'https://gateway.ai.cloudflare.com/v1/'.config('services.ai_gateway.account')."/{$gw}/google-ai-studio/v1beta/models/{$model}:generateContent"
            : "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
    }

    /** @return array<string, string> */
    private static function headers(string $feature): array
    {
        $key = config('services.gemini.key');
        if (! ($gw = self::gateway())) {
            return ['x-goog-api-key' => (string) $key];
        }

        // With BYOK the gateway adds the stored key itself; a key sent here would override it.
        return array_filter([
            'x-goog-api-key' => config('services.gemini.via_gateway') ? null : $key,
            'cf-aig-authorization' => 'Bearer '.config('services.ai_gateway.token'),
            // Up to five entries are kept per request (Cloudflare's limit); strings / numbers only.
            'cf-aig-metadata' => json_encode(array_filter(['family' => CurrentFamily::id(), 'user' => auth()->id(), 'feature' => $feature, 'env' => app()->environment()], fn ($v) => $v !== null)),
        ]);
    }
}
