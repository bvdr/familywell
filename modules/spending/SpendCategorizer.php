<?php

namespace Modules\Spending;

use App\Support\TypeSafe;
use Illuminate\Support\Collection;

// Bulk "sort my Other expenses into real categories" using TypeSafe's Jev. Driven from Spending
// settings: the client calls the recategorize endpoint batch after batch (cursor = last spend id)
// so it can draw a progress bar. Only spends still in `other` are touched, so re-running or
// resuming is always safe.
//
// One expense per Jev request, on purpose. Measured 2026-09-19 with 25 Romanian expenses in one
// request: answers drifted towards the end of the batch (Taxi -> Groceries 0.47, a bare "Lidl" with
// Education as runner-up), while one-per-request was clean on all 25 and honestly answered "other"
// for bare shop names. ~480 input tokens per expense, negligible at family scale.
class SpendCategorizer
{
    // Spends per HTTP batch. Each is a sequential Jev call (~0.5s), so keep the batch small enough
    // that the progress bar visibly moves and the request stays well under any proxy timeout.
    public const BATCH = 5;

    // Apply the pick only when the model gives "none of these fit" at most this much probability. The
    // Choice sums to 1, so a note torn between two sibling categories (a "BanaNe Mega" at Groceries 0.42
    // / Food 0.33 / other 0.23) still has a clear answer: it IS a real expense, just not obviously which
    // of two; either beats leaving it in "Other". A high "other" (bare "flori" 0.86, "Profi" 0.79) means
    // the note itself is too thin, and the spend stays put. Measured 2026-09-19 on 30 real-looking notes:
    // this rule matches a plain 0.6 top-probability threshold everywhere except the sibling splits.
    public const MAX_OTHER_PROBABILITY = 0.3;

    // What the built-in categories mean, so "Food" vs a custom "Groceries" is defined, not guessed.
    // Custom categories only have their name; admins name them descriptively anyway.
    private const DESCRIPTIONS = [
        'food' => 'Restaurants, cafés, takeaway, eating out',
        'transport' => 'Fuel, taxi, ride-hailing, public transport, parking, tolls',
        'lodging' => 'Rent, hotels, accommodation, home utilities',
        'activities' => 'Entertainment, tickets, sports, outings, hobbies',
        'shopping' => 'Clothes, gadgets, home goods, general retail',
        'education' => 'School, courses, books, tuition, kids\' classes',
        'health' => 'Pharmacy, doctors, dentist, vet, medical and wellness',
    ];

    /**
     * The Choice criteria for a family: key => "Name: meaning" (custom categories: just the name),
     * plus the explicit no-match option last. Empty when the family has no real category.
     *
     * @param  Collection<int, SpendCategory>  $categories
     * @return array<string, string>
     */
    public static function criteria(Collection $categories): array
    {
        $criteria = [];
        foreach ($categories as $c) {
            if ($c->key !== 'other') {
                $criteria[$c->key] = isset(self::DESCRIPTIONS[$c->key]) ? "{$c->name}: ".self::DESCRIPTIONS[$c->key] : $c->name;
            }
        }
        if ($criteria !== []) {
            $criteria['other'] = 'None of the categories above clearly fit';
        }

        return $criteria;
    }

    /**
     * One expense => one category key, or null when the model mostly says nothing fits (or the call
     * failed). `$expense` is the state shape both the bulk tool and the type-ahead suggestion send:
     * note, amount ("12.50 EUR"), paid_with, list, date — any of them may be absent.
     *
     * @param  array<string, string|null>  $expense
     * @param  array<string, string>  $criteria  from criteria()
     */
    public static function suggest(array $expense, array $criteria): ?string
    {
        if ($criteria === []) {
            return null;
        }
        $answer = TypeSafe::ask(['expense' => array_filter($expense)], ['category' => [
            'type' => 'choice',
            'instructions' => 'Which expense category best describes the household expense `expense`? Judge mainly by its note (in any language), then the list it belongs to and the amount.',
            'criteria' => $criteria,
        ]], 'spend_category')['category'] ?? null;

        $choice = $answer['choice'] ?? 'other';
        $pOther = (float) ($answer['probabilities']['other'] ?? 1);

        return $choice !== 'other' && isset($criteria[$choice]) && $pOther <= self::MAX_OTHER_PROBABILITY ? $choice : null;
    }

    /**
     * Ask Jev for a category for each spend. Returns spend id => category key for the spends it
     * could place confidently; the rest (answered "other", below threshold, or failed) are absent.
     *
     * @param  Collection<int, Spend>  $spends  with `source` loaded
     * @param  Collection<int, SpendCategory>  $categories  the family's categories
     * @return array<int, string>
     */
    public static function categorize(Collection $spends, Collection $categories): array
    {
        $criteria = self::criteria($categories);
        if ($criteria === [] || $spends->isEmpty()) {
            return [];
        }

        // Event names give the model the trip/household context ("Greece 2026", "Groceries").
        $events = SpendEvent::withoutGlobalScope('family')->whereIn('id', $spends->pluck('spend_event_id')->unique())->pluck('name', 'id');

        $picks = [];
        foreach ($spends as $s) {
            $key = self::suggest([
                'note' => $s->note,
                'amount' => number_format($s->amount / 100, 2, '.', '').' '.$s->currency,
                'paid_with' => $s->source?->name,
                'list' => $events[$s->spend_event_id] ?? null,
                'date' => $s->spent_at?->toDateString(),
            ], $criteria);
            if ($key !== null) {
                $picks[$s->id] = $key;
            }
        }

        return $picks;
    }
}
