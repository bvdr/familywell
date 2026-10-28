<?php

namespace Modules\Shopping;

use App\Support\Ai\AiGateway;
use RuntimeException;

// Turns a free-form spoken/typed grocery brain-dump ("milk, eggs, a few bananas for the kids, some
// pasta and don't forget dish soap") into structured, aisle-sorted items using Gemini. The model
// splits the run-on sentence, guesses a sensible aisle from the app's fixed list, and pulls out
// quantities and little notes ("for the kids"). We validate its output against DEPARTMENTS before it
// reaches the review screen.
class GroceryParser
{
    /** @param list<string> $departments allowed aisle slugs the model must choose from */
    public function __construct(private array $departments) {}

    // No Gemini (no key, no gateway key), no AI parse (the caller falls back to a naive split, and the UI still works).
    public static function configured(): bool
    {
        return AiGateway::gemini();
    }

    /**
     * @return list<array{name: string, quantity: string|null, department: string, note: string|null}>
     *
     * @throws RuntimeException when the model call fails
     */
    public function parse(string $transcript): array
    {
        $model = (string) config('services.gemini.model');

        $schema = [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string', 'description' => 'The product, singular and tidy, e.g. "Bananas"'],
                            'quantity' => ['type' => 'string', 'description' => 'Amount if mentioned, e.g. "1 gallon", "2 boxes"; empty if none'],
                            'department' => ['type' => 'string', 'enum' => $this->departments, 'description' => 'Best-fit grocery aisle'],
                            'note' => ['type' => 'string', 'description' => 'Any aside like "for the kids"; empty if none'],
                        ],
                        'required' => ['name', 'department'],
                    ],
                ],
            ],
            'required' => ['items'],
        ];

        $prompt = 'Turn this spoken grocery list into separate items. Split run-on phrases joined by "and", '
            .'commas or "some/a few". For each item give a tidy name, the quantity if stated, the best-fit '
            .'aisle from the allowed list, and any short aside as a note. Do not invent items. Transcript: '
            .'"'.trim($transcript).'"';

        // Through AiGateway: the hosted app's AI Gateway (logged per family / user) or Google directly (self-hosted).
        $out = AiGateway::run('grocery_parse', ['parse' => [$model, [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => $schema,
                'thinkingConfig' => ['thinkingBudget' => 0], // plain extraction, skip billable thinking tokens
            ],
        ]]])['parse'];
        if ($out === null) {
            throw new RuntimeException('Could not understand that. Try again.');
        }

        $data = json_decode((string) data_get($out, 'candidates.0.content.parts.0.text'), true);

        return $this->normalise($data['items'] ?? []);
    }

    /**
     * Naive, no-AI split of a pasted block (WhatsApp / notes / another chat) into candidate items.
     * One item per line AND per comma/semicolon; strips list noise (bullets, "1." numbering, "[ ]"
     * checkboxes, emoji) and pulls an easy count into the quantity ("2x milk", "milk x2", "2 lapte",
     * "milk (2)"). Aisle is always 'other': the AI pass (when configured) is what sorts aisles.
     * ponytail: regex heuristics, not a grammar; units ("2 kg") stay in the name on purpose.
     *
     * @return list<array{name: string, quantity: string|null, department: string, note: string|null}>
     */
    public static function splitPasted(string $text): array
    {
        // Units we fold into the quantity so "2 kg flour" becomes qty "2 kg", name "flour".
        $unit = '(?:kg|g|l|ml|pcs?|buc(?:[aă][țt]i)?|packs?|pachete?|boxe?s?|cutii?|bags?|pungi|bottles?|sticl[ăa]|sticle)';

        $out = [];
        foreach (preg_split('/[\r\n,;]+/u', $text) ?: [] as $line) {
            // Leading noise: bullets/dashes, "1." / "1)" / "1:" numbering, markdown + unicode checkboxes.
            $line = preg_replace('/^[\s\-\*\x{2022}\x{25E6}\x{25AA}\x{2013}\x{2014}>]+/u', '', $line);
            $line = preg_replace('/^\d{1,3}(?:[\)\:]|\.(?=\s))\s*/u', '', $line);
            $line = preg_replace('/^(?:\[\s*[xX\x{2713}\x{2714}]?\s*\]|[\x{2610}\x{2611}\x{2612}\x{2713}\x{2714}\x{2705}])\s*/u', '', $line);
            // Emoji anywhere ("🍞 Bread", "Bread 🥛"); variation selectors + ZWJ ride along with them.
            $line = preg_replace('/[\p{Extended_Pictographic}\x{FE0F}\x{200D}]+/u', ' ', $line);
            $line = trim(preg_replace('/\s+/u', ' ', $line) ?? '', " \t.-");
            if ($line === '') {
                continue;
            }

            $qty = null;
            if (preg_match('/^(?:x\s*)?(\d+(?:[\.,]\d+)?(?:\s*'.$unit.'\b)?)\s*(?:x|\x{00D7})?\s+(.+)$/iu', $line, $m)) {
                // "2x milk" / "x2 milk" / "2 lapte" / "2 kg flour"
                [$qty, $line] = [preg_replace('/\s+/', ' ', $m[1]), trim($m[2])];
            } elseif (preg_match('/^(.+?)\s*(?:\(\s*(\d+)\s*\)|\s(?:x|\x{00D7})\s*(\d+)|\s(\d+)\s*(?:x|\x{00D7}))$/iu', $line, $m)) {
                // "milk (2)" / "milk x2" / "milk 2x"
                $qty = implode('', array_slice($m, 2)); // only one of the three groups is non-empty
                $line = trim($m[1]);
            }

            $out[] = ['name' => $line, 'quantity' => $qty, 'department' => 'other', 'note' => null];
        }

        return $out;
    }

    /**
     * @param  array<int, mixed>  $items
     * @return list<array{name: string, quantity: string|null, department: string, note: string|null}>
     */
    private function normalise(array $items): array
    {
        $out = [];
        foreach ($items as $it) {
            $name = trim((string) ($it['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $dept = strtolower((string) ($it['department'] ?? 'other'));
            $qty = trim((string) ($it['quantity'] ?? ''));
            $note = trim((string) ($it['note'] ?? ''));
            $out[] = [
                'name' => $name,
                'quantity' => $qty !== '' ? $qty : null,
                'department' => in_array($dept, $this->departments, true) ? $dept : 'other',
                'note' => $note !== '' ? $note : null,
            ];
        }

        return $out;
    }
}
