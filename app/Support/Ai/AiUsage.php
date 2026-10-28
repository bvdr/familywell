<?php

namespace App\Support\Ai;

use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Facades\DB;

/**
 * The ai_usage ledger: every AI call we pay for, recorded by the client that made it (AiGateway for Gemini, TypeSafe,
 * AddReader for what the reader worker reports), so no feature can call a model without it being counted. Who (family + user) comes from the
 * request; background work after the response (defer) still has both. Cost is priced from config familywell.ai_rates.
 * Points are charged separately, per finished AI action (AiPoints::charge), not per call: typing in "Just say it"
 * reads several times per add.
 */
final class AiUsage
{
    public static function record(string $feature, string $provider, string $model, int $inputTokens, int $outputTokens = 0, int $points = 0): void
    {
        $rate = config("familywell.ai_rates.{$model}", []);
        $cost = (int) round($inputTokens * ($rate['in'] ?? 0) + $outputTokens * ($rate['out'] ?? 0)); // $/M tokens * tokens = micro-dollars

        // Never let metering break the feature it meters.
        rescue(fn () => DB::table('ai_usage')->insert([
            'family_id' => CurrentFamily::id(),
            'user_id' => auth()->id(),
            'feature' => $feature,
            'provider' => $provider,
            'model' => $model,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'cost_micros' => $cost,
            'points' => $points,
            'created_at' => now(),
        ]));
    }
}
