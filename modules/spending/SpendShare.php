<?php

namespace Modules\Spending;

use Illuminate\Database\Eloquent\Model;

// Per-spend split override row: one participant who shares a spend, with an optional exact amount in
// the SPEND's own currency (minor units); EventBalances converts it to the base currency. A null amount
// means a weighted share ("split with only these people"). See the create_spend_split_tables migration.
class SpendShare extends Model
{
    protected $table = 'spending_spend_shares';

    protected $fillable = ['spend_id', 'participant_key', 'amount'];

    protected $casts = ['amount' => 'integer'];
}
