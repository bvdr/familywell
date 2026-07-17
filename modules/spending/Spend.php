<?php

namespace Modules\Spending;

use App\Models\User;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Spend extends Model
{
    use BelongsToFamily;

    protected $table = 'spending_spends';

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
}
