<?php

namespace Modules\Spending;

use Illuminate\Database\Eloquent\Model;

// Per-event split settings for one participant (a family member 'u{id}' or guest 'g{id}'): their
// headcount (weight) and an optional covered_by. Rows exist only for non-default participants.
class SpendParticipant extends Model
{
    protected $table = 'spending_participants';

    protected $fillable = ['spend_event_id', 'participant_key', 'weight', 'covered_by'];

    protected $casts = ['weight' => 'integer'];
}
