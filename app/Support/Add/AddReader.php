<?php

namespace App\Support\Add;

use App\Support\Ai\AiUsage;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\UploadedFile;
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
     * @return list<array{kind: string, module: string, p: float, sure: bool, fields: array<string, array{value: mixed, confidence: float}>, ask: array<string, list<string>>, missing: list<string>, types: array<string, string>, target: ?string, people: list<string>, details: object}>
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
                // Field options are resolved for this person (their list names); 'hidden' is the app's own business, and
                // "Add details" fields aren't read at all (AddKinds 'details').
                'kinds' => array_map(fn ($k) => ['module' => $k['module'], 'fields' => array_map(fn ($f) => array_diff_key(
                    isset($f['options']) && $f['options'] instanceof \Closure ? array_replace($f, ['options' => ($f['options'])($ctx->user)]) : $f,
                    ['hidden' => 1],
                ), array_filter($k['fields'], fn ($f) => empty($f['details'])))], $kinds),
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

        // Where each card will land ("Household", or the list the input named: "Oana"): the app knows that, not the
        // reader. Fields the card doesn't show (the list: it's the destination) are listed for the sheet.
        // The "Add details" form of each kind found (choices resolved once per kind, not once per card).
        $details = [];
        $detailsOf = function (string $kind) use ($kinds, $ctx, &$details) {
            return $details[$kind] ??= array_map(fn ($f) => array_filter([
                'type' => $f['type'],
                'choices' => isset($f['choices']) ? ($f['choices'])($ctx->user) : null,
                'slot' => $f['slot'] ?? null,
                'badges' => $f['badges'] ?? null,
            ], fn ($x) => $x !== null), array_filter($kinds[$kind]['fields'], fn ($f) => ! empty($f['details'])));
        };

        return array_map(function (array $item) use ($kinds, $ctx, $detailsOf) {
            $kind = $kinds[$item['kind']];
            $values = array_map(fn ($f) => $f['value'] ?? null, $item['fields'] ?? []);
            $target = $kind['target'] ?? null;

            return $item + [
                'target' => $target ? rescue(fn () => $target($ctx->user, $ctx, $values), null) : null,
                'hidden' => array_keys(array_filter($kind['fields'], fn ($f) => ! empty($f['hidden']))),
                // List fields whose rows have an amount (shopping items); a project's tasks are names only.
                'quantity' => array_keys(array_filter($kind['fields'], fn ($f) => isset($f['of']['quantity']))),
                // Fields that name a family member ("who"): the details form shows the family's faces for them.
                'people' => array_keys(array_filter($kind['fields'], fn ($f) => ($f['ask'] ?? null) === 'member')),
                'details' => (object) $detailsOf($item['kind']), // {} not [] when a kind has none, for the sheet
            ];
        }, array_values(array_filter($response->json('items') ?? [], fn ($i) => isset($kinds[$i['kind'] ?? '']))));
    }

    /**
     * Voice (design H2c, "talk it out"): what was recorded so far, as words. The sheet re-sends the growing recording
     * every few seconds while the person talks, and the words go through read() like typing. The worker runs Whisper;
     * the seconds it heard go into the ledger (priced per second, config familywell.ai_rates).
     */
    public static function transcribe(UploadedFile $audio, AddContext $ctx): string
    {
        $response = Http::timeout(30)->withToken((string) config('services.add_reader.secret'))
            ->post(rtrim((string) config('services.add_reader.url'), '/').'/transcribe', [
                'audio' => base64_encode((string) file_get_contents($audio->getRealPath())),
                'meta' => ['family' => CurrentFamily::id(), 'user' => $ctx->user->id],
            ]);
        if ($response->failed()) {
            report(new RuntimeException('Add transcribe failed ('.$response->status().'): '.mb_substr($response->body(), 0, 300)));
            throw new RuntimeException('The reader is not available right now.');
        }
        foreach ($response->json('usage') ?? [] as $u) {
            AiUsage::record('add_voice', 'cloudflare', (string) $u['model'], (int) $u['input_tokens']);
        }

        return (string) $response->json('text', '');
    }
}
