<?php

namespace Modules\Spending;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;

class SpendSettlement extends Model
{
    use BelongsToFamily;

    protected $table = 'spending_settlements';

    // from_key/to_key are namespaced participant keys ('u{id}' member / 'g{id}' guest), so a transfer
    // can involve a guest. Matches Spend::participantKey() and SpendShare.participant_key.
    protected $fillable = ['spend_event_id', 'from_key', 'to_key', 'amount', 'note', 'created_by'];

    protected $casts = ['amount' => 'integer'];
}
