<?php

namespace Modules\Spending;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

// Turns a receipt photo into prefill fields for a spend, using Gemini vision. The model reads
// rotated / low-quality / multilingual receipts and returns structured JSON; we then validate and
// normalise it against the app's own category/currency lists before it ever reaches the form.
class ReceiptScanner
{
    /**
     * @param  list<string>  $categories  allowed spend categories the model must choose from
     * @param  list<string>  $currencies  allowed ISO 4217 codes
     */
    public function __construct(
        private array $categories,
        private array $currencies,
    ) {}

    // The whole feature is gated on this: no API key, no scanning (and the UI hides the entry).
    public static function configured(): bool
    {
        return filled(config('services.gemini.key'));
    }

    /**
     * @return array{amount: float|null, currency: string|null, date: string|null, category: string|null, note: string|null}
     *
     * @throws RuntimeException when the photo can't be read
     */
    public function scan(UploadedFile $file): array
    {
        $key = config('services.gemini.key');
        $model = config('services.gemini.model');

        $schema = [
            'type' => 'object',
            'properties' => [
                'amount' => ['type' => 'number', 'description' => 'Grand total actually paid'],
                'has_printed_total' => ['type' => 'boolean', 'description' => 'True only if an explicit grand-total line is printed on the receipt'],
                'currency' => ['type' => 'string', 'description' => 'ISO 4217 code, e.g. EUR'],
                'date' => ['type' => 'string', 'description' => 'Purchase date, YYYY-MM-DD'],
                'category' => ['type' => 'string', 'enum' => $this->categories],
                'note' => ['type' => 'string', 'description' => 'Short human-friendly description, in English'],
                'line_items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'price' => ['type' => 'number'],
                        ],
                    ],
                ],
            ],
            'required' => ['amount', 'currency', 'date', 'category'],
        ];

        $prompt = 'Extract the expense from this receipt photo. It may be rotated, low quality, or in any '
            .'language; give the note in English. amount = the grand total actually paid. Set has_printed_total '
            .'to true only when the receipt prints an explicit total line; otherwise list every line item so the '
            .'total can be summed. Pick the single best category from the allowed list. Use YYYY-MM-DD for date.';

        // thinkingBudget 0: this is plain extraction, not reasoning, so we skip (billable) thinking tokens.
        $response = Http::timeout(30)
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}", [
                'contents' => [[
                    'parts' => [
                        ['text' => $prompt],
                        ['inline_data' => [
                            'mime_type' => $file->getMimeType() ?: 'image/jpeg',
                            'data' => base64_encode((string) file_get_contents($file->getRealPath())),
                        ]],
                    ],
                ]],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'responseSchema' => $schema,
                    'thinkingConfig' => ['thinkingBudget' => 0],
                ],
            ]);

        if ($response->failed()) {
            // Log the provider error for us, but never leak it to the client.
            report(new RuntimeException('Gemini receipt scan failed ('.$response->status().'): '.$response->body()));
            throw new RuntimeException('Could not read the receipt. Try a clearer, well-lit photo.');
        }

        $data = json_decode((string) data_get($response->json(), 'candidates.0.content.parts.0.text'), true);
        if (! is_array($data)) {
            throw new RuntimeException('Could not read the receipt. Try a clearer, well-lit photo.');
        }

        return $this->normalise($data);
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array{amount: float|null, currency: string|null, date: string|null, category: string|null, note: string|null}
     */
    private function normalise(array $d): array
    {
        // Models read individual prices more accurately than they add them up, so when the receipt has
        // no printed grand total we sum the line items ourselves rather than trusting the model's total.
        $amount = (float) ($d['amount'] ?? 0);
        $items = $d['line_items'] ?? [];
        if (empty($d['has_printed_total']) && is_array($items) && $items !== []) {
            $sum = array_sum(array_map(fn ($i) => (float) ($i['price'] ?? 0), $items));
            if ($sum > 0) {
                $amount = $sum;
            }
        }

        $currency = strtoupper((string) ($d['currency'] ?? ''));
        $category = strtolower((string) ($d['category'] ?? ''));
        $date = (string) ($d['date'] ?? '');
        $note = trim((string) ($d['note'] ?? ''));

        return [
            'amount' => $amount > 0 ? round($amount, 2) : null,
            'currency' => in_array($currency, $this->currencies, true) ? $currency : null,
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null,
            'category' => in_array($category, $this->categories, true) ? $category : null,
            'note' => $note !== '' ? $note : null,
        ];
    }
}
