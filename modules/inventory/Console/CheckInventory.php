<?php

namespace Modules\Inventory\Console;

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Tenancy\FamilyClock;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Modules\Inventory\InventoryAlert;
use Modules\Inventory\InventoryItem;
use Modules\Inventory\InventoryReplenishment;
use Modules\Inventory\InventorySetting;
use Modules\Notifications\Notify;

/**
 * The daily inventory sweep. For every family that has inventory (and the module enabled) it:
 *   1. Raises notifications for items that need attention — low / empty / expired / stale — respecting
 *      the family's per-condition toggles (InventorySetting) and staying idempotent per condition/day
 *      via the InventoryAlert ledger, so a same-day re-run never double-fires.
 *   2. Auto-surfaces short stock onto Shopping when the family opted in (auto_add_low / auto_add_empty)
 *      and the Shopping module is enabled — the "automatic" half of the replenishment loop that P4 left
 *      as an explicit tap.
 *
 * Runs OUTSIDE any HTTP request, so it walks families itself and sets CurrentFamily per family (the
 * BelongsToFamily scope, Notify, ShoppingList::defaultFor and the Realtime broadcast all read it). It is
 * scheduled ->daily() from InventoryServiceProvider; it is also safe to run by hand
 * (`php artisan inventory:check`) for testing. Idempotent: re-running the same day changes nothing.
 */
class CheckInventory extends Command
{
    protected $signature = 'inventory:check';

    protected $description = 'Notify families about low/empty/expired/stale inventory and auto-add short stock to Shopping (daily).';

    public function handle(ModuleRegistry $modules): int
    {
        $prior = CurrentFamily::id(); // restore afterwards so a long-running worker isn't left scoped

        // Only families that actually hold inventory are worth walking (unscoped: we're outside a family
        // request). Small set — one query, then per-family work.
        $familyIds = InventoryItem::withoutGlobalScopes()
            ->select('family_id')->distinct()->pluck('family_id');

        $notified = 0;
        $addedToShopping = 0;

        try {
            foreach ($familyIds as $familyId) {
                $familyId = (int) $familyId;
                if (! $modules->isEnabled('inventory', $familyId)) {
                    continue; // module turned off for this family — nothing to do
                }

                CurrentFamily::set($familyId); // scope every read/write below to this family
                $family = Family::find($familyId);
                if (! $family) {
                    continue;
                }

                $settings = InventorySetting::current($familyId);
                $members = $family->members; // recipients for the alerts (no actor -> everyone)
                $items = InventoryItem::query()->get();

                // The once-a-day ledger counts the family's own day (FamilyClock), like "expiring" does.
                // ponytail: still scheduled once at 07:00 UTC for everyone; hourly + a local-hour gate like
                // birthdays:notify if a far-west family ever minds the timing.
                $notified += $this->raiseAlerts($items, $settings, $members, FamilyClock::today($familyId));
                $addedToShopping += $this->autoReplenish($family, $settings, $modules);
            }
        } finally {
            // Leave tenancy exactly as we found it (null-safe: forget when there was no prior scope).
            $prior !== null ? CurrentFamily::set($prior) : CurrentFamily::forget();
        }

        $this->info("Inventory check: {$notified} alert(s) sent, {$addedToShopping} item(s) auto-added to Shopping.");

        return self::SUCCESS;
    }

    /**
     * Raise a notification for each item that entered an enabled condition today, and prune alerts whose
     * condition has cleared or changed. Returns how many notifications were sent.
     *
     * @param  Collection<int, InventoryItem>  $items
     * @param  Collection<int, User>  $members
     */
    private function raiseAlerts($items, InventorySetting $settings, $members, string $today): int
    {
        $sent = 0;

        foreach ($items as $item) {
            $condition = $this->conditionFor($item); // low|empty|expired|stale|null

            // Any alert row for this item whose condition no longer holds is stale — drop it so the same
            // condition returning later notifies fresh. With no active condition, that clears every alert
            // for the item; with one, it clears all but the current condition's row.
            InventoryAlert::where('inventory_item_id', $item->id)
                ->when($condition !== null, fn ($q) => $q->where('condition', '!=', $condition))
                ->delete();

            if ($condition === null || ! $settings->notifies($condition)) {
                // No active condition, or the family muted this one at the policy level. If muted, also
                // clear any lingering row so re-enabling notifies fresh rather than being suppressed.
                if ($condition !== null) {
                    InventoryAlert::where('inventory_item_id', $item->id)->where('condition', $condition)->delete();
                }

                continue;
            }

            // Idempotency per condition/day: only fire when we haven't already notified today.
            $alert = InventoryAlert::firstOrNew(['inventory_item_id' => $item->id, 'condition' => $condition]);
            if ($alert->exists && optional($alert->last_notified_on)->toDateString() === $today) {
                continue; // already nudged about this condition today
            }

            [$title, $body] = $this->message($item, $condition);
            Notify::push($members, 'inventory', null, $title, $body, "/m/inventory/items/{$item->id}");

            $alert->last_notified_on = $today;
            $alert->save();
            $sent++;
        }

        return $sent;
    }

    /**
     * Auto-add short, auto-replenish stock onto the family's default Shopping list when they opted in.
     * Respects the low-vs-empty split, the Shopping module being enabled, and the loop's own idempotency
     * (InventoryReplenishment skips items already on a list). Returns how many rows were created.
     */
    private function autoReplenish(Family $family, InventorySetting $settings, ModuleRegistry $modules): int
    {
        if (! $settings->auto_add_low && ! $settings->auto_add_empty) {
            return 0; // nothing to do
        }
        if (! $modules->isEnabled('shopping', $family->id)) {
            return 0; // Shopping isn't installed for this family — can't surface anything
        }

        // A concrete actor for the created shopping rows (created_by). Prefer the owner, else any admin.
        $actor = User::find($family->owner_id)
            ?? $family->members()->wherePivot('role', 'admin')->first()
            ?? $family->members()->first();
        if (! $actor) {
            return 0;
        }

        $replenish = app(InventoryReplenishment::class);
        $added = 0;
        foreach ($replenish->replenishable() as $item) {
            if ($item->shopping_item_id !== null) {
                continue; // already on a list — idempotent skip
            }
            $state = $item->state();
            $wanted = ($state === 'low' && $settings->auto_add_low) || ($state === 'empty' && $settings->auto_add_empty);
            if ($wanted && $replenish->addToShopping($item, $actor)) {
                $added++;
            }
        }

        return $added;
    }

    /**
     * The single alert condition for an item, or null when it needs no attention. Mirrors the derived
     * state (one condition per item): expired/empty/low come straight from state(); an otherwise-fine but
     * long-untouched item is stale. Expiring-soon is deliberately not an alert condition (the spec's set
     * is low/empty/expired/stale) — it still shows on the Needs Attention screen.
     */
    private function conditionFor(InventoryItem $item): ?string
    {
        return match ($item->state()) {
            'expired' => 'expired',
            'empty' => 'empty',
            'low' => 'low',
            default => $item->isStale() ? 'stale' : null,
        };
    }

    /**
     * Notification title + body for a condition. English literals, matching the other modules' Notify
     * calls (task/spend notifications are not localised either — there is no per-recipient locale at send
     * time). A light quantity hint is added for low/empty so the push is actionable at a glance.
     *
     * @return array{0:string, 1:string}
     */
    private function message(InventoryItem $item, string $condition): array
    {
        $name = $item->name;
        $unit = $item->unit;

        return match ($condition) {
            'low' => ['Running low', "{$name} is running low ({$this->num($item->quantity)} {$unit} left)."],
            'empty' => ['Out of stock', "{$name} is empty — time to restock."],
            'expired' => ['Expired', "{$name} has passed its expiry date."],
            'stale' => ['Not checked recently', "{$name} hasn't been updated in over ".InventoryItem::STALE_AFTER_DAYS.' days.'],
            default => ['Inventory', $name],
        };
    }

    /** Trim a decimal for display (12.00 -> "12", 1.50 -> "1.5"); null (unknown) reads as "0". */
    private function num(?float $n): string
    {
        return rtrim(rtrim(number_format((float) ($n ?? 0), 2, '.', ''), '0'), '.');
    }
}
