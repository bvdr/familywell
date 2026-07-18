<?php

namespace Modules\Spending;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// A single receipt photo attached to a spend. `path` is the R2 object key (family-namespaced).
class SpendReceipt extends Model
{
    protected $table = 'spending_receipts';

    protected $fillable = ['spend_id', 'path'];

    public function spend(): BelongsTo
    {
        return $this->belongsTo(Spend::class, 'spend_id');
    }
}
