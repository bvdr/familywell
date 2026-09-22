<?php

namespace Modules\Spending;

use App\Models\Guest;
use App\Models\User;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Shopping\ShoppingShop;

class Spend extends Model
{
    use BelongsToFamily;

    protected $table = 'spending_spends';

    // receipt_path is legacy (single receipt); receipts are now rows in spending_receipts.
    protected $fillable = ['spend_event_id', 'payer_id', 'payer_guest_id', 'source_id', 'amount', 'currency', 'base_amount', 'rate', 'category', 'store', 'note', 'receipt_path', 'spent_at', 'created_by'];

    protected $casts = [
        'amount' => 'integer',      // original amount in `currency`, minor units
        'base_amount' => 'integer', // converted to the event currency, minor units (balances use this)
        'rate' => 'decimal:8',
        'spent_at' => 'date',
    ];

    // Mirror the column default so a freshly created model reads 'other' without a refresh.
    protected $attributes = ['category' => 'other'];

    protected static function booted(): void
    {
        // Every create path (add, quick-add, duplicate, guest add, receipt scan) funnels through here:
        // an expense left in "Other" gets an AI category guess after the response (see SpendAutoCategorizer).
        static::created(fn (self $spend) => SpendAutoCategorizer::queue($spend));
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
}
