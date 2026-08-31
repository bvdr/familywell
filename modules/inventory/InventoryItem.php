<?php

namespace Modules\Inventory;

use App\Models\User;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Shopping\ShoppingItem;

/**
 * A thing the family keeps, consumes and replenishes. Its STATE (good/low/empty/expiring/expired/
 * unknown) is never stored — it is derived here from quantity, min_quantity and expires_at, so it is
 * always consistent with the numbers and there is one source of truth for the UI, filters and tests.
 */
class InventoryItem extends Model
{
    use BelongsToFamily;

    protected $table = 'inventory_items';

    protected $fillable = [
        'name', 'quantity', 'unit', 'category', 'location', 'min_quantity',
        'target_quantity', 'expires_at', 'auto_replenish', 'shopping_item_id', 'notes', 'created_by', 'family_id',
    ];

    protected $casts = [
        'quantity' => 'float',          // NULL stays NULL (the "unknown" state); a number casts to float
        'min_quantity' => 'float',
        'target_quantity' => 'float',
        'expires_at' => 'date',
        'auto_replenish' => 'boolean',
    ];

    // The units a quantity can be measured in. Kept here (not a DB enum) so adding a unit is a code
    // change, and validation reads from one list.
    public const UNITS = ['pieces', 'g', 'kg', 'ml', 'L', 'packs', 'bottles', 'boxes'];

    // An item counts as "expiring" when it expires within this many days (inclusive). One knob, so the
    // overview card, the All Items filter and the item detail all agree on "expiring soon".
    public const EXPIRING_WITHIN_DAYS = 7;

    // An otherwise-fine item counts as "stale" when nothing has touched it in this long — a soft nudge
    // ("have we run out and forgotten to log it?"), surfaced only on the Needs Attention screen. It is
    // deliberately NOT one of the derived STATES: staleness is orthogonal to stock level (a good/unknown
    // item that's simply gone quiet), so folding it into state() would muddy the one-state-per-item rule.
    public const STALE_AFTER_DAYS = 45;

    // The derived states, most-urgent first. `state()` returns exactly one; this order IS the tie-break
    // when several conditions hold at once (see state()).
    public const STATES = ['expired', 'empty', 'expiring', 'low', 'unknown', 'good'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(InventoryLog::class)->latest();
    }

    /**
     * The OPEN shopping item this stock has been surfaced onto, or null. Set when the item is added to
     * Shopping (P4 loop) and cleared once that shopping item is bought — so a non-null link always means
     * "there is a live, not-yet-bought shopping row for this item". nullOnDelete on the FK keeps it honest
     * if the shopping item is removed straight from the Shopping UI.
     */
    public function shoppingItem(): BelongsTo
    {
        return $this->belongsTo(ShoppingItem::class, 'shopping_item_id');
    }

    /**
     * The single derived state for this item. Precedence (most urgent wins), so an item that is both
     * expired and empty reads as "expired":
     *   expired  — has an expiry date in the past
     *   empty    — a known quantity of exactly 0
     *   expiring — expires within EXPIRING_WITHIN_DAYS days
     *   low      — a known quantity at/below min_quantity (min_quantity 0 disables this threshold)
     *   unknown  — no measured quantity (quantity is NULL) — a first-class, non-error state
     *   good     — has stock, above min, not near expiry
     *
     * Rationale for unknown below the actionable states: expiry/emptiness/low are things to act on;
     * "unknown" only means "we haven't measured", so a known-actionable signal (e.g. expiring) should
     * still surface over it.
     */
    public function state(): string
    {
        $qty = $this->quantity; // float|null

        if ($this->expires_at !== null && $this->expires_at->isPast() && ! $this->expires_at->isToday()) {
            return 'expired';
        }

        if ($qty !== null && $qty <= 0) {
            return 'empty';
        }

        if ($this->expires_at !== null && $this->expires_at->lte(now()->addDays(self::EXPIRING_WITHIN_DAYS))) {
            return 'expiring';
        }

        if ($qty !== null && $this->min_quantity > 0 && $qty <= $this->min_quantity) {
            return 'low';
        }

        if ($qty === null) {
            return 'unknown';
        }

        return 'good';
    }

    // The states that mean "act on this now" — the stock is short or the item is going/gone off. Kept as
    // one list so every screen (overview counts, Needs Attention buckets, category/location attention
    // tallies) agrees on what "needs attention" means.
    public const ATTENTION_STATES = ['expired', 'empty', 'expiring', 'low'];

    /** True when this item wants replenishing (drives the "Needs attention" list + the P4 Shopping loop). */
    public function needsAttention(): bool
    {
        return in_array($this->state(), self::ATTENTION_STATES, true);
    }

    // The states that mean "we are short on stock and should buy more" — the ones the Shopping loop acts
    // on. Deliberately NOT expiring/expired: an item can be going off while still fully stocked, and
    // "throw it out" is not "buy another", so expiry is a Needs-Attention signal but not a replenishment
    // one. Buying more is driven purely by how much is left (low = at/below min, empty = none).
    public const REPLENISH_STATES = ['low', 'empty'];

    /** True when the stock is short enough to want buying more (low or empty). Drives the replenishment screen. */
    public function wantsReplenishment(): bool
    {
        return in_array($this->state(), self::REPLENISH_STATES, true);
    }

    /**
     * True when this item is already surfaced on a live shopping list. The link column is cleared the
     * moment its shopping item is bought, so a non-null value reliably means "an open shopping row still
     * exists" — the single fact the loop uses to stay idempotent (never add the same item twice while one
     * is still open).
     */
    public function onShoppingList(): bool
    {
        return $this->shopping_item_id !== null;
    }

    /**
     * The quantity this item should be topped up to when a purchase lands (Shopping -> Inventory). Prefer
     * the explicit target ("full"); else fall back to twice the minimum, matching barFraction()'s notion
     * of "full = 2x min" so the bar reads full after a buy; else, with neither set, 1 — just enough to
     * lift an empty/low item out of that state. Always returns a positive number, so a purchase always
     * records a concrete replenished amount and the item leaves the low/empty state.
     */
    public function replenishTarget(): float
    {
        if ($this->target_quantity !== null && $this->target_quantity > 0) {
            return (float) $this->target_quantity;
        }
        if ($this->min_quantity > 0) {
            return (float) $this->min_quantity * 2;
        }

        return max(1.0, (float) ($this->quantity ?? 0));
    }

    /**
     * True when this item is fine on stock (good or unknown) but has gone untouched for STALE_AFTER_DAYS.
     * Only non-attention items can be stale — anything already low/empty/expiring surfaces under its own,
     * more urgent, state instead, so the Needs Attention buckets never double-count an item. `updated_at`
     * is the last time anything about the item changed (a quantity move touches it), so it stands in for
     * "last checked" without needing to read the log.
     */
    public function isStale(): bool
    {
        if ($this->needsAttention()) {
            return false;
        }

        return $this->updated_at !== null && $this->updated_at->lt(now()->subDays(self::STALE_AFTER_DAYS));
    }

    /**
     * The fraction (0..1) a quantity bar should fill, or null when there's nothing to measure against.
     * Denominator is the target ("full") level if set, else twice the min (so "at min" reads as
     * half-full). Unknown quantity has no bar.
     */
    public function barFraction(): ?float
    {
        $qty = $this->quantity;
        if ($qty === null) {
            return null;
        }
        $denominator = $this->target_quantity ?: ($this->min_quantity > 0 ? $this->min_quantity * 2 : null);
        if (! $denominator || $denominator <= 0) {
            return null;
        }

        return max(0.0, min(1.0, $qty / $denominator));
    }

    /**
     * The wire shape every screen renders an item from. camelCase for the client; `state`/`bar` are the
     * derived values so the frontend never re-implements the logic.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'quantity' => $this->quantity,        // number|null (null => unknown)
            'unit' => $this->unit,
            'category' => $this->category,         // category key
            'location' => $this->location,         // location key
            'minQuantity' => $this->min_quantity,
            'targetQuantity' => $this->target_quantity,
            'expiresAt' => optional($this->expires_at)->toDateString(),
            'autoReplenish' => $this->auto_replenish,
            'notes' => $this->notes,
            // Shopping-loop link. `onShoppingList` (from the column) tells every screen whether to show
            // "Shopping · Added" instead of an Add button; `shoppingListId` is filled only when the
            // relation is eager-loaded, so the badge can deep-link to the list (null => badge without a link).
            'onShoppingList' => $this->onShoppingList(),
            'shoppingListId' => $this->relationLoaded('shoppingItem') ? optional($this->shoppingItem)->shopping_list_id : null,
            'state' => $this->state(),
            'stale' => $this->isStale(),   // soft "gone quiet" flag, orthogonal to state (Needs Attention)
            'bar' => $this->barFraction(),
            'createdBy' => $this->created_by,
            'updatedAt' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
