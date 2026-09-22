<?php

namespace Modules\Spending;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
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
     * @return array{amount: float|null, currency: string|null, date: string, category: string|null, note: string|null, store: string|null}
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
                'store' => ['type' => 'string', 'description' => 'Merchant / shop brand name as printed on the receipt header (e.g. "Lidl", "Kaufland"), without address, legal form (SRL, GmbH, Ltd) or branch number; empty if unreadable'],
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
            .'total can be summed. Pick the single best category from the allowed list. Use YYYY-MM-DD for date. '
            .'store = the shop brand name only (no address or legal suffix), or empty when not readable.';

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
     * @return array{amount: float|null, currency: string|null, date: string, category: string|null, note: string|null, store: string|null}
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
        $note = trim((string) ($d['note'] ?? ''));
        // Shop name, capped to the column width; the form's picker lets the user fix a misread.
        $store = mb_substr(trim((string) ($d['store'] ?? '')), 0, 120);

        return [
            'amount' => $amount > 0 ? round($amount, 2) : null,
            'currency' => in_array($currency, $this->currencies, true) ? $currency : null,
            'date' => $this->normaliseDate((string) ($d['date'] ?? '')),
            'category' => in_array($category, $this->categories, true) ? $category : null,
            'note' => $note !== '' ? $note : null,
            'store' => $store !== '' ? $store : null,
        ];
    }

    // Turn the model's raw date into a plausible purchase date. This is a scan-it-now flow, so the
    // receipt is almost always from the current year — yet the year is exactly the field OCR mis-reads
    // most (faint thermal receipts turn 2026 into 2020/2023/2024, or a stray digit lands in the future).
    // The month and day come through far more reliably, so we keep those and force the year to the
    // current one. Year-boundary safety: if the current year would make the date land in the future
    // (a December receipt scanned in January), it must belong to the previous year — never emit a future
    // date. Anything with no usable month/day falls back to today, which the user reviews before saving.
    private function normaliseDate(string $raw): string
    {
        $today = Carbon::now();

        // Must be a real YYYY-MM-DD calendar date. createFromFormat silently rolls overflow (2026-13-45
        // becomes 2027-01-14), so we also require it to round-trip back to the same string.
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $today->toDateString();
        }
        try {
            $date = Carbon::createFromFormat('!Y-m-d', $raw); // '!' zeroes the time so gt()/year read cleanly
        } catch (\Throwable) {
            return $today->toDateString();
        }
        if ($date === false || $date->format('Y-m-d') !== $raw) {
            return $today->toDateString();
        }

        // Keep the month/day the model read, but overwrite the (often mis-read) year with the current one.
        $month = $date->month;
        $day = $date->day;
        $year = $today->year;

        // A Feb 29 read off a receipt has no home in a non-leap current year; fall back to today.
        if (! checkdate($month, $day, $year)) {
            return $today->toDateString();
        }
        $resolved = Carbon::create($year, $month, $day, 0, 0, 0);

        // Forcing the current year can push a late-in-year date past today (scanning a December receipt
        // in January). A purchase can't be in the future, so it belongs to the previous year instead.
        if ($resolved->gt($today)) {
            if (! checkdate($month, $day, $year - 1)) {
                return $today->toDateString();
            }
            $resolved = Carbon::create($year - 1, $month, $day, 0, 0, 0);
        }

        return $resolved->toDateString();
    }
}
