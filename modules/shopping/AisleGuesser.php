<?php

namespace Modules\Shopping;

use App\Events\ShoppingChanged;
use App\Support\Realtime;
use App\Support\TypeSafe;

// Sorts freshly added items that landed in the catch-all "Other" aisle into a real one using
// TypeSafe's Jev (a System One model: it answers typed multiple-choice questions, it doesn't
// generate text). The whole feature is gated on TYPESAFE_API_KEY: no key, items just stay in
// "Other" exactly as before.
//
// Flow: ShoppingItem::created -> queue() buffers the item -> ONE deferred request after the
// response is sent (so the add stays instant and a 100-item voice/paste batch is one call, not
// 100) -> matching items get their `department` updated and the family's open lists re-sort
// over the ShoppingChanged realtime event.
class AisleGuesser
{
    /** @var array<int, ShoppingItem> items created during this request that still sit in "other" */
    private static array $pending = [];

    public static function configured(): bool
    {
        return TypeSafe::configured();
    }

    public static function queue(ShoppingItem $item): void
    {
        if (! self::configured() || $item->department !== 'other') {
            return;
        }
        self::$pending[$item->id] = $item;
        // Named defer => Laravel keeps only the last registration, so N creates schedule ONE flush.
        defer(fn () => self::flush(), 'shopping.aisle-guess');
    }

    public static function flush(): void
    {
        $items = self::$pending;
        self::$pending = []; // reset before doing anything: Octane keeps statics across requests

        // Aisle sets are per shop, so group by the store the item will be bought at (item's own
        // store, else its list's default store; null => the Grocery template).
        $groups = [];
        foreach ($items as $item) {
            $groups[(string) ($item->store ?? $item->list?->store)][] = $item;
        }

        $touchedFamilies = [];
        foreach ($groups as $store => $group) {
            $aisles = ShoppingAisle::resolve($store ?: null, $group[0]->family_id);
            $criteria = array_column($aisles, 'name', 'slug');
            unset($criteria['other']);
            if ($criteria === []) {
                continue;
            }
            $criteria['other'] = 'None of the aisles above clearly fit';

            // ponytail: one request per store, all items as questions; chunk if a batch ever
            // exceeds the API's request size.
            $state = ['items' => array_values(array_map(fn (ShoppingItem $i) => array_filter([
                'id' => $i->id,
                'name' => $i->name,
                'quantity' => $i->quantity,
                'note' => $i->note,
            ]), $group))];
            $questions = [];
            foreach (array_keys($state['items']) as $idx) {
                $questions['item_'.$state['items'][$idx]['id']] = [
                    'type' => 'choice',
                    'instructions' => "In which aisle of the shop would a shopper find the product `items[{$idx}].name`?",
                    'criteria' => $criteria,
                ];
            }

            $answers = TypeSafe::ask($state, $questions, 'aisle_guess');
            foreach ($group as $item) {
                $choice = $answers['item_'.$item->id]['choice'] ?? 'other';
                if ($choice === 'other' || ! isset($criteria[$choice])) {
                    continue;
                }
                // `department = other` guard: if someone re-aisled it by hand in the meantime, they win.
                $moved = ShoppingItem::withoutGlobalScope('family')
                    ->whereKey($item->id)->where('department', 'other')
                    ->update(['department' => $choice]);
                if ($moved) {
                    $touchedFamilies[$item->family_id] = true;
                }
            }
        }

        foreach (array_keys($touchedFamilies) as $familyId) {
            Realtime::broadcast(new ShoppingChanged((int) $familyId));
        }
    }
}
