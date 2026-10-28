<?php

namespace App\Support\Add;

use App\Support\Ai\AiGateway;
use RuntimeException;

/**
 * Reads "Just say it" input (text and/or up to 4 images) into the things to add. Two backends, same result:
 *  - Cloudflare (hosted, Workers AI configured): Clef answers one yes/no per kind with a probability ("is there an
 *    event?"), Clairic reads every kind's fields with a confidence each, and an LLM (Gemini Flash when there's a key,
 *    else Llama 4 Scout on Workers AI) reads them too, all in parallel (see cloudflare()).
 *  - Gemini only (e.g. self-hosted with just GEMINI_API_KEY): one structured-output call returning the same shape;
 *    its likelihoods are self-reported, so less calibrated than Clef's.
 * Every call goes through AiGateway (Cloudflare AI Gateway when configured: usage logged per family / user).
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

    // Workers AI's multilingual vision model: the LLM reader when there's no Gemini key, and the fallback when Gemini fails.
    private const SCOUT = 'meta/llama-4-scout-17b-16e-instruct';

    // Tested 2026-10-28: with this wording and every key required, Scout split "cumpără lapte și pâine, și sună la
    // instalator mâine" into lapte / pâine + "sună la instalator" (a vaguer prompt gave back the whole sentence).
    private const PROMPT = 'You read what a person wants to add to their family organiser and split it into the fields. Keep their language and their words. Fill only what the input or the photo says; use null (or an empty list) when it is not there.';

    public static function available(): bool
    {
        return AiGateway::workersAi() || AiGateway::gemini();
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

        [$likely, $fields] = AiGateway::workersAi()
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
            // Unsure AND nothing read into it (a shopping card for "new list of todouri"): just noise, so it's dropped.
            if ($p < self::SURE && $values === []) {
                continue;
            }
            // Required fields the reader didn't find: the card shows them to fill in and starts unticked.
            $missing = array_keys(array_filter($def['fields'], fn ($f, $name) => ! empty($f['required']) && ! isset($values[$name]), ARRAY_FILTER_USE_BOTH));
            $types = array_map(fn ($f) => $f['type'], $def['fields']);
            $target = isset($def['target']) ? rescue(fn () => ($def['target'])($ctx->user, $ctx), null) : null;
            $items[] = ['kind' => $kind, 'module' => $def['module'], 'target' => $target, 'p' => round($p, 3), 'sure' => $p >= self::SURE, 'fields' => $values, 'ask' => $ask, 'missing' => $missing, 'types' => $types];
        }
        usort($items, fn ($a, $b) => $b['p'] <=> $a['p']);

        // An unsure card that only repeats a sure card's words is the same reading twice ("new list of todouri" as a
        // to-do list AND a shopping list): keep the sure one.
        $said = fn (array $it) => array_map(fn ($f) => mb_strtolower(trim(is_array($f['value']) ? implode(',', $f['value']) : (string) $f['value'])), $it['fields']);
        $sure = array_merge([], ...array_map(fn ($it) => array_values($said($it)), array_filter($items, fn ($it) => $it['sure'])));
        $items = array_values(array_filter($items, fn ($it) => $it['sure'] || array_diff(array_values($said($it)), $sure) !== []));

        return $items;
    }

    /**
     * Clef decides what is in the input, Clairic reads the details with a calibrated confidence each, and Llama 4 Scout
     * (Workers AI's multilingual vision model) reads them too: Clairic is English-first and copies text out of what it is
     * given, so from a picture it only gets lettering (a car came back as make "ŠK", model "Sunday"; Scout said Skoda
     * Octavia, live test 2026-10-28). All in one parallel round; either one failing leaves the other's values.
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
            'kinds' => ['workers-ai', 'cloudflare/clef', ['state' => $state, 'questions' => $questions] + $media],
            // Only a photo: no date / member lines for the extractor to copy into a field.
            'fields' => ['workers-ai', 'cloudflare/clairic', ['state' => $imageOnly ? trim(strstr($facts, "\nWhat they gave:")) : $facts, 'fields' => $fields, 'reference_date' => now()->toDateString()] + $media],
        ];
        // An LLM reads too: Clairic is English-first (Romanian items came back at 0.15, "cump" as an item) and only
        // copies text out of a photo. Gemini Flash (when there's a key) runs in this same round: it's quick. Scout is
        // slow to write a full answer (22 fields took 4.3s, measured 2026-10-28), so it only runs when needed, after
        // the first round and only for the kinds Clef found: a photo, or a likely card Clairic is unsure of or missing
        // something on. Gemini failing (an outage, spent credits) falls back to that same Scout call.
        if (AiGateway::gemini()) {
            $calls['llm'] = self::llmCall('gemini', $facts, $images, $kinds);
        }
        $out = AiGateway::run('add_read', $calls);
        if ($out['kinds'] === null) {
            throw new RuntimeException('The reader is not available right now.');
        }

        $likely = array_map(fn ($a) => (float) ($a['noul'] ?? 0), $out['kinds']['answers'] ?? []);
        $values = [];
        foreach ($out['fields']['answers'] ?? [] as $key => $a) {
            // A value the model thinks is absent (high p_null) is dropped rather than shown as a guess.
            $values[$key] = ($a['p_null'] ?? 0) > 0.5 ? null : ['value' => $a['value'] ?? null, 'confidence' => (float) ($a['confidence'] ?? 0)];
        }

        $engine = 'gemini';
        $llm = $out['llm'] ?? null;
        if ($llm === null) {
            $found = array_filter($kinds, fn ($def, $kind) => ($likely[$kind] ?? 0) >= self::MIN, ARRAY_FILTER_USE_BOTH);
            // "Who" doesn't count: an unsure one becomes a question to the person anyway.
            $unsure = fn ($def, $kind) => collect($def['fields'])->contains(fn ($f, $name) => ! isset($f['ask']) && (($v = $values["{$kind}_{$name}"] ?? null) === null
                ? ! empty($f['required'])
                : $v['confidence'] < self::FIELD_SURE));
            if ($found && ($images || array_filter($found, $unsure, ARRAY_FILTER_USE_BOTH))) {
                $engine = 'scout';
                $llm = AiGateway::run('add_read', ['llm' => self::llmCall('scout', $facts, $images, $found)])['llm'];
            }
        }
        if ($llm !== null) {
            // An LLM has no calibrated confidence: a value it gives counts as fairly sure, "who" as sure only if it said
            // so. It wins for words (text, lists: Clairic is English-first, it kept "cumpără lapte și pâine" as a to-do at
            // 0.5+), for a photo (Clairic only sees its lettering), and wherever Clairic is unsure or empty. Clairic
            // keeps the dates, times and amounts it is sure of: that's what it reads best.
            $types = array_merge(...array_map(fn ($kind, $def) => array_combine(
                array_map(fn ($n) => "{$kind}_{$n}", array_keys($def['fields'])), array_column($def['fields'], 'type')), array_keys($kinds), $kinds));
            foreach (self::llmValues(self::llmData($engine, $llm), $kinds) as $key => $v) {
                $mine = $values[$key] ?? null;
                $words = in_array($types[$key] ?? 'text', ['text', 'list'], true);
                if ($v['value'] !== null && $v['value'] !== '' && $v['value'] !== [] && ($images || $words || $mine === null || $mine['confidence'] < self::FIELD_SURE)) {
                    $values[$key] = $v;
                }
            }
        }

        return [$likely, $values];
    }

    /** Gemini alone (no Workers AI): one call that also says how likely each kind is. */
    private static function gemini(string $state, array $images, array $kinds): array
    {
        $out = AiGateway::run('add_read', ['llm' => self::llmCall('gemini', $state, $images, $kinds, likelihoods: true)]);
        if ($out['llm'] === null) {
            throw new RuntimeException('The reader is not available right now.');
        }
        $data = self::llmData('gemini', $out['llm']);
        $likely = array_map(fn ($kind) => (float) ($data["{$kind}_likelihood"] ?? 0), array_combine(array_keys($kinds), array_keys($kinds)));

        return [$likely, self::llmValues($data, $kinds)];
    }

    /** One LLM reading call (an AiGateway call), Gemini Flash or Llama 4 Scout, with the fields as a JSON schema. */
    private static function llmCall(string $engine, string $state, array $images, array $kinds, bool $likelihoods = false): array
    {
        if ($engine === 'scout') {
            $properties = self::schema($kinds, 'json');

            return ['workers-ai', self::SCOUT, [
                'messages' => [['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => self::PROMPT."\n\n".$state],
                    ...array_map(fn ($uri) => ['type' => 'image_url', 'image_url' => ['url' => $uri]], $images),
                ]]],
                'response_format' => ['type' => 'json_schema', 'json_schema' => ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties)]],
            ]];
        }

        $properties = self::schema($kinds, 'gemini');
        if ($likelihoods) {
            foreach ($kinds as $kind => $def) {
                $properties["{$kind}_likelihood"] = ['type' => 'number', 'description' => 'How likely (0 to 1) the answer is yes to: '.$def['question']];
            }
        }
        $parts = [['text' => self::PROMPT."\n\n".$state]];
        foreach ($images as $uri) {
            // Gemini wants the bare base64 and its type apart.
            preg_match('#^data:([^;]+);base64,(.*)$#s', $uri, $m);
            $parts[] = ['inline_data' => ['mime_type' => $m[1] ?? 'image/jpeg', 'data' => $m[2] ?? '']];
        }

        return ['google-ai-studio', (string) config('services.gemini.model'), [
            'contents' => [['parts' => $parts]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => ['type' => 'object', 'properties' => $properties],
                'thinkingConfig' => ['thinkingBudget' => 0], // plain extraction, no billable thinking
            ],
        ]];
    }

    /** The JSON object an LLM answered with: Gemini's text part, Scout's `response` (or chat content). */
    private static function llmData(string $engine, array $answer): array
    {
        $data = $engine === 'gemini'
            ? json_decode((string) data_get($answer, 'candidates.0.content.parts.0.text'), true)
            : ($answer['response'] ?? json_decode((string) data_get($answer, 'choices.0.message.content'), true));

        return is_array($data) ? $data : [];
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
