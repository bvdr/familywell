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

    // Workers AI's vision model, for photos (see cloudflare()).
    private const VISION = 'meta/llama-4-scout-17b-16e-instruct';

    private const PROMPT = 'Read what this person wants to add to their family organiser. Fill only what the input or the photo shows; leave the rest empty.';

    public static function available(): bool
    {
        return WorkersAi::configured() || filled(config('services.gemini.key'));
    }

    /**
     * @param  list<string>  $images  data: URIs (PNG / JPEG / WebP): Workers AI takes them as they are
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
            ? self::cloudflare($ctx->text.$given, $ctx->facts.$given, trim($text) === '', $images, $kinds)
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

    /**
     * Clef decides what is in the input, Clairic reads the details; for a photo, Llama 4 Scout (Workers AI's vision
     * model) reads them too, because Clairic's text fields copy text out of what they're given: from a picture it only
     * gets visible lettering (a car came back as make "ŠK", model "Sunday" from the date line; Scout said Skoda Octavia,
     * live test 2026-10-28). All in one parallel round; Clairic's values stay as the fallback if Scout fails.
     *
     * @return array{0: array<string, float>, 1: array<string, array{value: mixed, confidence: float}>}
     */
    private static function cloudflare(string $state, string $facts, bool $imageOnly, array $images, array $kinds): array
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
        $calls = [
            'kinds' => ['cloudflare/clef', ['state' => $state, 'questions' => $questions] + $media],
            // Only a photo: no date / member lines for the extractor to copy into a field.
            'fields' => ['cloudflare/clairic', ['state' => $imageOnly ? trim(strstr($facts, "\nWhat they gave:")) : $facts, 'fields' => $fields, 'reference_date' => now()->toDateString()] + $media],
        ];
        if ($images) {
            $calls['vision'] = [self::VISION, [
                'messages' => [['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => self::PROMPT."\n\n".$state],
                    ...array_map(fn ($uri) => ['type' => 'image_url', 'image_url' => ['url' => $uri]], $images),
                ]]],
                'response_format' => ['type' => 'json_schema', 'json_schema' => ['type' => 'object', 'properties' => self::schema($kinds, 'json')]],
            ]];
        }
        $out = WorkersAi::run('add_read', $calls);
        if ($out['kinds'] === null) {
            throw new RuntimeException('The reader is not available right now.');
        }

        $likely = array_map(fn ($a) => (float) ($a['noul'] ?? 0), $out['kinds']['answers'] ?? []);
        $values = [];
        foreach ($out['fields']['answers'] ?? [] as $key => $a) {
            // A value the model thinks is absent (high p_null) is dropped rather than shown as a guess.
            $values[$key] = ($a['p_null'] ?? 0) > 0.5 ? null : ['value' => $a['value'] ?? null, 'confidence' => (float) ($a['confidence'] ?? 0)];
        }
        if ($vision = $out['vision'] ?? null) {
            $read = $vision['response'] ?? json_decode((string) data_get($vision, 'choices.0.message.content'), true);
            // An LLM has no calibrated confidence: a value it gives counts as fairly sure, "who" as sure only if it said so.
            foreach (self::llmValues(is_array($read) ? $read : [], $kinds) as $key => $v) {
                if ($v['value'] !== null && $v['value'] !== '' && $v['value'] !== []) {
                    $values[$key] = $v;
                }
            }
        }

        return [$likely, $values];
    }

    /** @return array{0: array<string, float>, 1: array<string, array{value: mixed, confidence: float}>} */
    private static function gemini(string $state, array $images, array $kinds): array
    {
        $properties = self::schema($kinds, 'gemini');
        foreach ($kinds as $kind => $def) {
            $properties["{$kind}_likelihood"] = ['type' => 'number', 'description' => 'How likely (0 to 1) the answer is yes to: '.$def['question']];
        }
        $model = (string) config('services.gemini.model');
        $parts = [['text' => self::PROMPT."\n\n".$state]];
        foreach ($images as $uri) {
            // Gemini wants the bare base64 and its type apart.
            preg_match('#^data:([^;]+);base64,(.*)$#s', $uri, $m);
            $parts[] = ['inline_data' => ['mime_type' => $m[1] ?? 'image/jpeg', 'data' => $m[2] ?? '']];
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

        $likely = array_map(fn ($kind) => (float) ($data["{$kind}_likelihood"] ?? 0), array_combine(array_keys($kinds), array_keys($kinds)));
        $values = self::llmValues($data, $kinds);

        return [$likely, $values];
    }

    /**
     * The per-kind field schema for an LLM reader: "{kind}_{field}" properties, plus "_sure" for an 'ask' field.
     * Gemini's schema dialect marks optional values with `nullable`; Workers AI takes standard JSON Schema.
     */
    private static function schema(array $kinds, string $dialect): array
    {
        $nullable = fn (string $type) => $dialect === 'gemini' ? ['type' => $type, 'nullable' => true] : ['type' => [$type, 'null']];
        $properties = [];
        foreach ($kinds as $kind => $def) {
            foreach ($def['fields'] as $name => $f) {
                $properties["{$kind}_{$name}"] = match ($f['type']) {
                    'number' => $nullable('number'),
                    'list' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'date' => $nullable('string') + ['description' => 'YYYY-MM-DD. '],
                    'datetime' => $nullable('string') + ['description' => 'YYYY-MM-DDTHH:MM:SS. '],
                    default => $nullable('string'),
                };
                $properties["{$kind}_{$name}"]['description'] = ($properties["{$kind}_{$name}"]['description'] ?? '').$f['instructions'];
                if (($f['ask'] ?? null) === 'member') {
                    $properties["{$kind}_{$name}_sure"] = ['type' => 'boolean', 'description' => 'True only if the input clearly says who'];
                }
            }
        }

        return $properties;
    }

    /** @return array<string, array{value: mixed, confidence: float}> an LLM's answer in the reader's field shape */
    private static function llmValues(array $data, array $kinds): array
    {
        $values = [];
        foreach ($kinds as $kind => $def) {
            foreach ($def['fields'] as $name => $f) {
                $sure = ($f['ask'] ?? null) !== 'member' || ! empty($data["{$kind}_{$name}_sure"]);
                $values["{$kind}_{$name}"] = ['value' => $data["{$kind}_{$name}"] ?? null, 'confidence' => $sure ? 0.8 : 0.3];
            }
        }

        return $values;
    }
}
