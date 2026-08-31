<?php

namespace Modules\Inventory;

use App\Models\User;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'target_quantity', 'expires_at', 'auto_replenish', 'notes', 'created_by', 'family_id',
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

    /** True when this item wants replenishing (drives the "Needs attention" list + the P4 Shopping loop). */
    public function needsAttention(): bool
    {
        return in_array($this->state(), ['expired', 'empty', 'expiring', 'low'], true);
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
            'state' => $this->state(),
            'bar' => $this->barFraction(),
            'createdBy' => $this->created_by,
            'updatedAt' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
