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

    // A pick below this probability is ignored (the spend stays in "other"): a diffuse answer means
    // nothing really fits, and a wrong category costs the user a manual fix.
    public const MIN_PROBABILITY = 0.6;

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
     * Ask Jev for a category for each spend. Returns spend id => category key for the spends it
     * could place confidently; the rest (answered "other", below threshold, or failed) are absent.
     *
     * @param  Collection<int, Spend>  $spends  with `source` loaded
     * @param  Collection<int, SpendCategory>  $categories  the family's categories
     * @return array<int, string>
     */
    public static function categorize(Collection $spends, Collection $categories): array
    {
        $criteria = [];
        foreach ($categories as $c) {
            if ($c->key !== 'other') {
                $criteria[$c->key] = isset(self::DESCRIPTIONS[$c->key]) ? "{$c->name}: ".self::DESCRIPTIONS[$c->key] : $c->name;
            }
        }
        if ($criteria === [] || $spends->isEmpty()) {
            return [];
        }
        $criteria['other'] = 'None of the categories above clearly fit';

        // Event names give the model the trip/household context ("Greece 2026", "Groceries").
        $events = SpendEvent::withoutGlobalScope('family')->whereIn('id', $spends->pluck('spend_event_id')->unique())->pluck('name', 'id');

        $picks = [];
        foreach ($spends as $s) {
            $expense = array_filter([
                'note' => $s->note,
                'amount' => number_format($s->amount / 100, 2, '.', '').' '.$s->currency,
                'paid_with' => $s->source?->name,
                'list' => $events[$s->spend_event_id] ?? null,
                'date' => $s->spent_at?->toDateString(),
            ]);
            $answer = TypeSafe::ask(['expense' => $expense], ['category' => [
                'type' => 'choice',
                'instructions' => 'Which expense category best describes the household expense `expense`? Judge mainly by its note (in any language), then the list it belongs to and the amount.',
                'criteria' => $criteria,
            ]])['category'] ?? null;

            $choice = $answer['choice'] ?? 'other';
            $p = (float) ($answer['probabilities'][$choice] ?? 0);
            if ($choice !== 'other' && isset($criteria[$choice]) && $p >= self::MIN_PROBABILITY) {
                $picks[$s->id] = $choice;
            }
        }

        return $picks;
    }
}
