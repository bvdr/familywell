<?php

namespace Modules\Spending;

use App\Models\User;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SpendEvent extends Model
{
    use BelongsToFamily;

    protected $table = 'spending_events';

    protected $fillable = ['name', 'currency', 'created_by'];

    /** Participants the spends are split among (snapshot of the family at creation). */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'spending_event_user', 'spend_event_id', 'user_id');
    }

    public function spends(): HasMany
    {
        return $this->hasMany(Spend::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(SpendSettlement::class);
    }
}
