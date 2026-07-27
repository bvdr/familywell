<?php

namespace Modules\Shopping;

use Illuminate\Support\Facades\Http;
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

    // No API key, no AI parse (the caller falls back to a naive split, and the UI still works).
    public static function configured(): bool
    {
        return filled(config('services.gemini.key'));
    }

    /**
     * @return list<array{name: string, quantity: string|null, department: string, note: string|null}>
     *
     * @throws RuntimeException when the model call fails
     */
    public function parse(string $transcript): array
    {
        $key = config('services.gemini.key');
        $model = config('services.gemini.model');

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

        $response = Http::timeout(30)
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}", [
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'responseSchema' => $schema,
                    'thinkingConfig' => ['thinkingBudget' => 0], // plain extraction, skip billable thinking tokens
                ],
            ]);

        if ($response->failed()) {
            report(new RuntimeException('Gemini grocery parse failed ('.$response->status().'): '.$response->body()));
            throw new RuntimeException('Could not understand that. Try again.');
        }

        $data = json_decode((string) data_get($response->json(), 'candidates.0.content.parts.0.text'), true);

        return $this->normalise($data['items'] ?? []);
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
