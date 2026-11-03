<?php

namespace Modules\Shopping;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Auto-clean (design S1d / S2c): bought items leave their list N days after they were ticked, into Recently deleted
 * (the module's 30-day soft delete), so a long-lived list doesn't fill with struck-through rows. N is the list's own
 * clean_after_days (0 = never) or else the family's setting, when its auto-clean is on. Daily.
 */
class CleanBoughtItems extends Command
{
    protected $signature = 'shopping:clean-bought';

    protected $description = 'Move bought shopping items past their list\'s clean-up time to Recently deleted';

    public function handle(): int
    {
        $settings = DB::table('shopping_settings')->get()->keyBy('family_id');
        $moved = 0;
        foreach (ShoppingList::withoutGlobalScopes()->get(['id', 'family_id', 'clean_after_days']) as $list) {
            $family = $settings[$list->family_id] ?? null;
            // Lists in a family with no settings row yet follow the defaults (on, 7 days).
            $days = $list->clean_after_days ?? (($family->auto_clean ?? true) ? ($family->clean_after_days ?? 7) : 0);
            if ($days < 1) {
                continue;
            }
            // Through the model, one by one: SoftDeletes + CascadesTrash keep Recently deleted and restore right.
            ShoppingItem::withoutGlobalScopes()->where('shopping_list_id', $list->id)->where('bought', true)
                ->where('bought_at', '<', now()->subDays($days))
                ->each(function (ShoppingItem $item) use (&$moved) {
                    $item->delete();
                    $moved++;
                });
        }
        $this->info("Moved {$moved} bought items to Recently deleted.");

        return self::SUCCESS;
    }
}
