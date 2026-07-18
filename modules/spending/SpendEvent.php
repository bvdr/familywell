<?php

namespace Modules\Spending;

use App\Models\User;
use App\Support\Sharing\ProvidesPublicShareView;
use App\Support\Sharing\Shareable;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SpendEvent extends Model implements ProvidesPublicShareView
{
    use BelongsToFamily;
    use Shareable;

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

    /** Read-only payload for the public invite link (no auth, no receipts, no editing). */
    public function publicShareView(): array
    {
        $members = $this->members()->get(['users.id', 'name']);
        $spends = $this->spends()->with('payer:id,name')->latest('spent_at')->latest('id')->get();

        return [
            'component' => 'spending/public-event',
            'props' => [
                'event' => [
                    'name' => $this->name,
                    'currency' => $this->currency,
                    'total' => (int) $spends->sum('base_amount'),
                    'members' => $members->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values(),
                ],
                'spends' => $spends->map(fn (Spend $s) => [
                    'id' => $s->id,
                    'amount' => $s->amount,
                    'currency' => $s->currency,
                    'base_amount' => $s->base_amount,
                    'category' => $s->category,
                    'note' => $s->note,
                    'payer' => $s->payer?->name,
                    'spent_at' => $s->spent_at->toDateString(),
                ])->values(),
            ],
        ];
    }
}
