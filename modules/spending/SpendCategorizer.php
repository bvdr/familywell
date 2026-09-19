<?php

namespace Modules\Spending;

use App\Support\TypeSafe;
use Illuminate\Support\Collection;

// Bulk "sort my Other expenses into real categories" using TypeSafe's Jev. Driven from Spending
// settings: the client calls the recategorize endpoint batch after batch (cursor = last spend id)
// so it can draw a progress bar, and each batch is one Jev request with one Choice question per
// spend. Only spends still in `other` are touched, so re-running or resuming is always safe.
class SpendCategorizer
{
    public const BATCH = 25;

    /**
     * Ask Jev for a category for each spend. Returns spend id => category key for the spends it
     * could place; spends it left in "other" (or unknown answers) are simply absent.
     *
     * @param  Collection<int, Spend>  $spends  with `source` loaded
     * @param  Collection<int, SpendCategory>  $categories  the family's categories
     * @return array<int, string>
     */
    public static function categorize(Collection $spends, Collection $categories): array
    {
        $criteria = $categories->pluck('name', 'key')->except('other')->all();
        if ($criteria === [] || $spends->isEmpty()) {
            return [];
        }
        $criteria['other'] = 'None of the categories above clearly fit';

        // Event names give the model the trip/household context ("Greece 2026", "Groceries").
        $events = SpendEvent::withoutGlobalScope('family')->whereIn('id', $spends->pluck('spend_event_id')->unique())->pluck('name', 'id');

        $rows = $spends->values()->map(fn (Spend $s) => array_filter([
            'id' => $s->id,
            'note' => $s->note,
            'amount' => number_format($s->amount / 100, 2, '.', '').' '.$s->currency,
            'paid_with' => $s->source?->name,
            'list' => $events[$s->spend_event_id] ?? null,
            'date' => $s->spent_at?->toDateString(),
        ]))->all();

        $questions = [];
        foreach ($rows as $idx => $row) {
            $questions['spend_'.$row['id']] = [
                'type' => 'choice',
                'instructions' => "Which expense category best describes the household expense `expenses[{$idx}]` (judge mainly by its note, then the list it belongs to and the amount)?",
                'criteria' => $criteria,
            ];
        }

        $answers = TypeSafe::ask(['expenses' => $rows], $questions);

        $picks = [];
        foreach ($rows as $row) {
            $choice = $answers['spend_'.$row['id']]['choice'] ?? 'other';
            if ($choice !== 'other' && isset($criteria[$choice])) {
                $picks[$row['id']] = $choice;
            }
        }

        return $picks;
    }
}
