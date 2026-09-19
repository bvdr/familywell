<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;

// Thin client for TypeSafe's System One endpoint (Jev). You send `state` (any JSON) plus a map of
// typed questions (choice / noul / score) and get one typed answer per question back: no prompt
// engineering, no free text to parse. Gated on TYPESAFE_API_KEY; every caller checks configured()
// first and treats the feature as absent without it.
class TypeSafe
{
    public static function configured(): bool
    {
        return filled(config('services.typesafe.key'));
    }

    /**
     * One evaluation call. Returns the `answers` map keyed like `$questions` (empty on failure,
     * which is reported but never thrown: callers use Jev as a nicety on top of already-saved data).
     *
     * @param  array<string, array{type: string, instructions: mixed, criteria?: mixed}>  $questions
     * @return array<string, array<string, mixed>>
     */
    public static function ask(array|string $state, array $questions): array
    {
        $response = Http::timeout(20)
            ->withToken((string) config('services.typesafe.key'))
            ->post('https://api.typesafe.ai/v1/systemone', [
                'state' => $state,
                'model' => config('services.typesafe.model'),
                'questions' => $questions,
            ]);

        if ($response->failed()) {
            report(new RuntimeException('TypeSafe call failed ('.$response->status().'): '.$response->body()));

            return [];
        }

        return $response->json('answers') ?? [];
    }
}
