<?php

namespace App\Support\Ai;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cloudflare Workers AI over REST (services.workers_ai: account + a token scoped to Workers AI). Runs several models in
 * parallel (a reader asks Clef and Clairic at once) and records each call in the ai_usage ledger. Failures are reported,
 * never thrown: a failed model returns null and the caller degrades (the Add sheet keeps Classic).
 */
final class WorkersAi
{
    public static function configured(): bool
    {
        return filled(config('services.workers_ai.account')) && filled(config('services.workers_ai.token'));
    }

    /**
     * @param  array<string, array{0: string, 1: array<string, mixed>}>  $calls  key => [publisher/model, payload], e.g. 'kinds' => ['cloudflare/clef', [...]]
     * @return array<string, array<string, mixed>|null> key => the model's `result`, null when that call failed
     */
    public static function run(string $feature, array $calls): array
    {
        $base = 'https://api.cloudflare.com/client/v4/accounts/'.config('services.workers_ai.account').'/ai/run/@cf/';
        $responses = Http::pool(fn (Pool $pool) => collect($calls)->map(fn ($call, $key) => $pool->as($key)
            ->timeout(15)->withToken((string) config('services.workers_ai.token'))
            ->post($base.$call[0], $call[1]))->all());

        $out = [];
        foreach ($calls as $key => [$model]) {
            $r = $responses[$key] ?? null;
            if (! $r instanceof Response || $r->failed() || ! $r->json('success')) {
                report(new RuntimeException("Workers AI {$model} failed: ".($r instanceof Response ? $r->status().' '.$r->body() : 'no response')));
                $out[$key] = null;

                continue;
            }
            // Cloudflare's own models report input_tokens; chat models (Llama) report prompt / completion tokens.
            AiUsage::record($feature, 'cloudflare', basename($model), (int) $r->json('result.usage.input_tokens', $r->json('result.usage.prompt_tokens', 0)), (int) $r->json('result.usage.completion_tokens', 0));
            $out[$key] = $r->json('result');
        }

        return $out;
    }
}
