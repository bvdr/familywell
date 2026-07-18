<?php

namespace Modules\Spending;

use App\Models\User;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Spend extends Model
{
    use BelongsToFamily;

    protected $table = 'spending_spends';

    // receipt_path is legacy (single receipt); receipts are now rows in spending_receipts.
    protected $fillable = ['spend_event_id', 'payer_id', 'amount', 'currency', 'base_amount', 'rate', 'category', 'note', 'receipt_path', 'spent_at', 'created_by'];

    protected $casts = [
        'amount' => 'integer',      // original amount in `currency`, minor units
        'base_amount' => 'integer', // converted to the event currency, minor units (balances use this)
        'rate' => 'decimal:8',
        'spent_at' => 'date',
    ];

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_id');
    }

    /** Receipt photos attached to this spend (many). */
    public function receipts(): HasMany
    {
        return $this->hasMany(SpendReceipt::class, 'spend_id');
    }
}
