<?php

namespace App\Support\Add;

use App\Support\Ai\AiUsage;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * "Just say it" reads through the FamilyWell reader, a separate, private Cloudflare Worker (bvdr/familywell-reader):
 * how the input is understood (which models, their wording, thresholds, merging) lives there so it can be tuned and
 * evaluated without an app release, and isn't part of this source-available repo. This app says WHAT can be added
 * (AddKinds: kinds, fields, types) and WHERE the person is (AddContext); the reader answers with cards.
 *
 * Hosted only: without services.add_reader (url + secret) available() is false and the Add sheet is Classic only,
 * which is what a self-hosted install gets. The reader reports the tokens each model used; they go into the ai_usage
 * ledger like every other AI call (the points are charged on save, AddController).
 */
final class AddReader
{
    public static function available(): bool
    {
        return filled(config('services.add_reader.url')) && filled(config('services.add_reader.secret'));
    }

    /**
     * @param  list<string>  $images  data: URIs (PNG / JPEG / WebP), downscaled by the browser
     * @return list<array{kind: string, module: string, p: float, sure: bool, fields: array<string, array{value: mixed, confidence: float}>, ask: array<string, list<string>>, missing: list<string>, types: array<string, string>, target: ?string}>
     */
    public static function read(string $text, array $images, AddContext $ctx): array
    {
        $kinds = AddKinds::enabled();
        if ($kinds === [] || (trim($text) === '' && $images === [])) {
            return [];
        }

        $response = Http::timeout(20)->withToken((string) config('services.add_reader.secret'))
            ->post(rtrim((string) config('services.add_reader.url'), '/').'/read', [
                'text' => $text,
                'images' => $images,
                'kinds' => array_map(fn ($k) => ['module' => $k['module'], 'fields' => $k['fields']], $kinds),
                'context' => $ctx->payload(),
                'meta' => ['family' => CurrentFamily::id(), 'user' => $ctx->user->id], // tags the reader's AI Gateway logs
            ]);
        if ($response->failed()) {
            report(new RuntimeException('Add reader failed ('.$response->status().'): '.mb_substr($response->body(), 0, 300)));
            throw new RuntimeException('The reader is not available right now.');
        }

        foreach ($response->json('usage') ?? [] as $u) {
            AiUsage::record('add_read', $u['provider'] === 'google-ai-studio' ? 'gemini' : 'cloudflare', (string) $u['model'], (int) $u['input_tokens'], (int) $u['output_tokens']);
        }

        // Where each card will land ("Household"): the app knows that, not the reader.
        return array_map(function (array $item) use ($kinds, $ctx) {
            $target = $kinds[$item['kind']]['target'] ?? null;

            return $item + ['target' => $target ? rescue(fn () => $target($ctx->user, $ctx), null) : null];
        }, array_values(array_filter($response->json('items') ?? [], fn ($i) => isset($kinds[$i['kind'] ?? '']))));
    }
}
