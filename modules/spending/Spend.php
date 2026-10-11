<?php

namespace Modules\Spending;

use App\Models\Guest;
use App\Models\User;
use App\Support\Media\StoredImage;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Modules\Shopping\ShoppingShop;

class Spend extends Model
{
    use BelongsToFamily;

    // Deleting an expense goes to the 30-day trash; its shares + receipt rows stay attached so a restore
    // is complete. Receipt photos leave storage only when the trash is purged (forceDeleting below).
    use SoftDeletes;

    protected $table = 'spending_spends';

    // receipt_path is legacy (single receipt); receipts are now rows in spending_receipts.
    protected $fillable = ['spend_event_id', 'payer_id', 'payer_guest_id', 'source_id', 'amount', 'currency', 'base_amount', 'rate', 'category', 'store', 'note', 'receipt_path', 'spent_at', 'created_by', 'count_in_monthly'];

    protected $casts = [
        'amount' => 'integer',      // original amount in `currency`, minor units
        'base_amount' => 'integer', // converted to the event currency, minor units (balances use this)
        'rate' => 'decimal:8',
        'spent_at' => 'date',
        'count_in_monthly' => 'boolean', // null = follow the list (SpendEvent::count_in_monthly)
    ];

    // Mirror the column default so a freshly created model reads 'other' without a refresh.
    protected $attributes = ['category' => 'other'];

    protected static function booted(): void
    {
        // Every create path (add, quick-add, duplicate, guest add, receipt scan) funnels through here:
        // an expense left in "Other" gets an AI category guess after the response (see SpendAutoCategorizer).
        static::created(fn (self $spend) => SpendAutoCategorizer::queue($spend));

        // Final delete (trash purge): remove the receipt photos from R2. Moved here from destroySpend so a
        // trashed expense keeps its photos until it can no longer be restored. Rows cascade in the DB.
        static::forceDeleting(function (self $spend) {
            foreach ($spend->receipts as $r) {
                StoredImage::delete($r->path); // removes the original + its thumb
            }
            if ($spend->receipt_path) {
                Storage::disk('r2')->delete($spend->receipt_path); // legacy single receipt, if present
            }
        });
    }

    /**
     * Shop names to suggest on the add/edit form: every store already used on a spend plus the family's
     * Shopping-module shops, so one spelling is shared across both modules. Sorted, de-duplicated.
     *
     * @return list<string>
     */
    public static function shopNames(): array
    {
        return static::query()->whereNotNull('store')->distinct()->pluck('store')
            ->merge(ShoppingShop::pluck('name'))
            ->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    /**
     * Does a spend on one of $eventIds read as the same purchase as this validated input? "Same" = same
     * entered amount + currency, same day, and the same shop when one is given (else the same category).
     * Deliberately simple: it powers a soft "looks like a duplicate" warning, not a block. Callers bound
     * $eventIds to what the actor may see (a member: their visible lists; a guest: the one linked list),
     * so the warning never reveals a private list's spend. $exceptId skips the spend being edited.
     *
     * @param  iterable<int>  $eventIds
     */
    public static function looksDuplicate(array $data, iterable $eventIds, ?int $exceptId = null): bool
    {
        $store = trim((string) ($data['store'] ?? ''));

        return static::query()
            ->whereIn('spend_event_id', $eventIds)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->where('amount', (int) round($data['amount'] * 100)) // same rounding as SpendEvent::buildSpend
            ->where('currency', $data['currency'])
            ->whereDate('spent_at', Carbon::parse($data['spent_at'])->toDateString())
            ->when($store !== '', fn ($q) => $q->whereRaw('LOWER(store) = ?', [mb_strtolower($store)]), fn ($q) => $q->where('category', $data['category']))
            ->exists();
    }

    /** The spending list this expense belongs to. */
    public function event(): BelongsTo
    {
        return $this->belongsTo(SpendEvent::class, 'spend_event_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_id');
    }

    public function payerGuest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'payer_guest_id');
    }

    /** Stable participant key across users and guests, so balances never collide on raw ids. */
    public function participantKey(): string
    {
        return $this->payer_guest_id ? 'g'.$this->payer_guest_id : 'u'.$this->payer_id;
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(SpendSource::class, 'source_id');
    }

    /** Receipt photos attached to this spend (many). */
    public function receipts(): HasMany
    {
        return $this->hasMany(SpendReceipt::class, 'spend_id');
    }

    /** Per-spend split override rows (which participants share it, optional exact amounts). */
    public function shares(): HasMany
    {
        return $this->hasMany(SpendShare::class, 'spend_id');
    }

    /**
     * Only spends that count toward the monthly figures (MonthToDate, global analytics, the shop hint,
     * spending_summary): an explicit per-expense flag wins, otherwise the list's setting. The list's own
     * total/stats/settle-up never use this, so excluded spends still count there. One SQL condition with
     * a subquery, so callers keep their single grouped query.
     */
    public function scopeCountsInMonthly(Builder $query): Builder
    {
        $table = $this->getTable();

        return $query->where(fn (Builder $q) => $q
            ->where("{$table}.count_in_monthly", true)
            ->orWhere(fn (Builder $q) => $q->whereNull("{$table}.count_in_monthly")
                ->whereIn("{$table}.spend_event_id", SpendEvent::withoutGlobalScopes()->where('count_in_monthly', true)->select('id'))));
    }
}
