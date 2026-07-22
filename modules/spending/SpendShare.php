<?php

namespace Modules\Spending;

use Illuminate\Database\Eloquent\Model;

// Per-spend split override row: one participant who shares a spend, with an optional exact amount
// (base-currency cents). See the create_spend_split_tables migration for the row semantics.
class SpendShare extends Model
{
    protected $table = 'spending_spend_shares';

    protected $fillable = ['spend_id', 'participant_key', 'amount'];

    protected $casts = ['amount' => 'integer'];
}
