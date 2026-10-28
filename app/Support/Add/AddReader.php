<?php

namespace App\Support\Add;

use App\Support\Ai\AiUsage;
use App\Support\Ai\WorkersAi;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads "Just say it" input (text and/or up to 4 images) into the things to add. Two backends, same result:
 *  - Cloudflare (hosted, WorkersAi configured): Clef answers one yes/no per kind with a probability ("is there an
 *    event?"), Clairic reads every kind's fields with a confidence each. Both run in parallel (~0.5s).
 *  - Gemini (fallback, e.g. self-hosted with only GEMINI_API_KEY): one structured-output call returning the same
 *    shape; its likelihoods are self-reported, so less calibrated than Clef's.
 * Neither configured: available() is false and the sheet is Classic only.
 *
 * An item is only offered when its kind is likely enough (MIN); below SURE the card asks "Add this as …?" instead of
 * assuming. A field the reader isn't sure about stays visible but marked; an unsure 'ask' => 'member' field becomes a
 * "which of you?" question. Nothing is saved here: the person confirms in the sheet first.
 */
final class AddReader
{
    public const MIN = 0.35;

    public const SURE = 0.7;

    // A field below this confidence is shown as "check this" (and an 'ask' field becomes a question)…
    public const FIELD_SURE = 0.5;

    // …and below this one it isn't shown at all.
    public const FIELD_MIN = 0.25;

    public static function available(): bool
    {
        return WorkersAi::configured() || filled(config('services.gemini.key'));
    }

    /**
     * @param  list<string>  $images  base64 (no data: prefix), PNG / JPEG / WebP
     * @return list<array{kind: string, module: string, p: float, sure: bool, fields: array<string, array{value: mixed, confidence: float}>, ask: array<string, list<string>>, missing: list<string>, types: array<string, string>}>
     */
    public static function read(string $text, array $images, AddContext $ctx): array
    {
        $kinds = AddKinds::enabled();
        if ($kinds === [] || (trim($text) === '' && $images === [])) {
            return [];
        }
        $given = "\nWhat they gave:".(trim($text) !== '' ? ' '.trim($text) : ' (only the image)');

        [$likely, $fields] = WorkersAi::configured()
            ? self::cloudflare($ctx->text.$given, $ctx->facts.$given, $images, $kinds)
            : self::gemini($ctx->text.$given, $images, $kinds);

        $items = [];
        foreach ($kinds as $kind => $def) {
            $p = (float) ($likely[$kind] ?? 0);
            if ($p < self::MIN) {
                continue;
            }
            $values = [];
            $ask = [];
            foreach ($def['fields'] as $name => $f) {
                $v = $fields["{$kind}_{$name}"] ?? null;
                // A value the reader barely believes (a due date at 0.13) is left out rather than saved as a guess.
                if ($v === null || $v['value'] === null || $v['value'] === '' || $v['value'] === [] || $v['confidence'] < self::FIELD_MIN) {
                    continue;
                }
                $values[$name] = $v;
                // Who it's for is only taken as given when that person is actually named in the input; a name the
                // reader picked from the family list ("Lily" for "call grandma") becomes a "which of you?" question.
                if (($f['ask'] ?? null) === 'member' && ($v['confidence'] < self::FIELD_SURE || ! $ctx->memberId((string) $v['value'])
                    || ! str_contains(mb_strtolower($text), mb_strtolower(explode(' ', (string) $v['value'])[0])))) {
                    $ask[$name] = $ctx->memberNames();
                }
            }
            // Required fields the reader didn't find: the card shows them to fill in and starts unticked.
            $missing = array_keys(array_filter($def['fields'], fn ($f, $name) => ! empty($f['required']) && ! isset($values[$name]), ARRAY_FILTER_USE_BOTH));
            $types = array_map(fn ($f) => $f['type'], $def['fields']);
            $items[] = ['kind' => $kind, 'module' => $def['module'], 'p' => round($p, 3), 'sure' => $p >= self::SURE, 'fields' => $values, 'ask' => $ask, 'missing' => $missing, 'types' => $types];
        }
        usort($items, fn ($a, $b) => $b['p'] <=> $a['p']);

        return $items;
    }

    /** @return array{0: array<string, float>, 1: array<string, array{value: mixed, confidence: float}>} */
    private static function cloudflare(string $state, string $facts, array $images, array $kinds): array
    {
        $questions = [];
        $fields = [];
        foreach ($kinds as $kind => $def) {
            $questions[$kind] = ['type' => 'noul', 'instructions' => $def['question']];
            foreach ($def['fields'] as $name => $f) {
                $fields["{$kind}_{$name}"] = array_diff_key($f, ['ask' => 1, 'required' => 1]);
            }
        }
        $media = $images ? ['images' => $images] : [];
        $out = WorkersAi::run('add_read', [
            'kinds' => ['clef', ['state' => $state, 'questions' => $questions] + $media],
            'fields' => ['clairic', ['state' => $facts, 'fields' => $fields, 'reference_date' => now()->toDateString()] + $media],
        ]);
        if ($out['kinds'] === null) {
            throw new RuntimeException('The reader is not available right now.');
        }

        $likely = array_map(fn ($a) => (float) ($a['noul'] ?? 0), $out['kinds']['answers'] ?? []);
        $values = [];
        foreach ($out['fields']['answers'] ?? [] as $key => $a) {
            // A value the model thinks is absent (high p_null) is dropped rather than shown as a guess.
            $values[$key] = ($a['p_null'] ?? 0) > 0.5 ? null : ['value' => $a['value'] ?? null, 'confidence' => (float) ($a['confidence'] ?? 0)];
        }

        return [$likely, $values];
    }

    /** @return array{0: array<string, float>, 1: array<string, array{value: mixed, confidence: float}>} */
    private static function gemini(string $state, array $images, array $kinds): array
    {
        $type = fn (array $f) => match ($f['type']) {
            'number' => ['type' => 'number', 'nullable' => true],
            'list' => ['type' => 'array', 'items' => ['type' => 'string']],
            'date' => ['type' => 'string', 'nullable' => true, 'description' => 'YYYY-MM-DD'],
            'datetime' => ['type' => 'string', 'nullable' => true, 'description' => 'YYYY-MM-DDTHH:MM:SS'],
            default => ['type' => 'string', 'nullable' => true],
        };
        $properties = [];
        foreach ($kinds as $kind => $def) {
            $properties["{$kind}_likelihood"] = ['type' => 'number', 'description' => 'How likely (0 to 1) the answer is yes to: '.$def['question']];
            foreach ($def['fields'] as $name => $f) {
                $properties["{$kind}_{$name}"] = $type($f) + ['description' => $f['instructions']];
                if (($f['ask'] ?? null) === 'member') {
                    $properties["{$kind}_{$name}_sure"] = ['type' => 'boolean', 'description' => 'True only if the input clearly says who'];
                }
            }
        }
        $model = (string) config('services.gemini.model');
        $parts = [['text' => "Read what this person wants to add to their family organiser. Fill only what the input says; leave the rest empty.\n\n".$state]];
        foreach ($images as $b64) {
            $parts[] = ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => $b64]];
        }
        $response = Http::timeout(30)->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=".config('services.gemini.key'), [
            'contents' => [['parts' => $parts]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => ['type' => 'object', 'properties' => $properties],
                'thinkingConfig' => ['thinkingBudget' => 0], // plain extraction, no billable thinking
            ],
        ]);
        if ($response->failed()) {
            report(new RuntimeException('Gemini add read failed ('.$response->status().'): '.$response->body()));
            throw new RuntimeException('The reader is not available right now.');
        }
        AiUsage::record('add_read', 'gemini', $model, (int) $response->json('usageMetadata.promptTokenCount', 0), (int) $response->json('usageMetadata.candidatesTokenCount', 0));
        $data = json_decode((string) $response->json('candidates.0.content.parts.0.text'), true) ?: [];

        $likely = [];
        $values = [];
        foreach ($kinds as $kind => $def) {
            $likely[$kind] = (float) ($data["{$kind}_likelihood"] ?? 0);
            foreach ($def['fields'] as $name => $f) {
                $sure = ($f['ask'] ?? null) === 'member' ? ! empty($data["{$kind}_{$name}_sure"]) : true;
                $values["{$kind}_{$name}"] = ['value' => $data["{$kind}_{$name}"] ?? null, 'confidence' => $sure ? 0.9 : 0.3];
            }
        }

        return [$likely, $values];
    }
}
