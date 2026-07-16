<?php

namespace Modules\Spending;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;

class SpendSettlement extends Model
{
    use BelongsToFamily;

    protected $table = 'spending_settlements';

    protected $fillable = ['spend_event_id', 'from_user_id', 'to_user_id', 'amount', 'created_by'];

    protected $casts = ['amount' => 'integer'];
}
