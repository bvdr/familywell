<?php

namespace Modules\Spending;

use App\Models\User;
use App\Support\Guests\GuestShareable;
use App\Support\Guests\ProvidesGuestView;
use App\Support\Sharing\ProvidesPublicShareView;
use App\Support\Sharing\Shareable;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SpendEvent extends Model implements ProvidesGuestView, ProvidesPublicShareView
{
    use BelongsToFamily;
    use GuestShareable; // guests() + guestLink() come from here
    use Shareable;

    protected $table = 'spending_events';

    protected $fillable = ['name', 'currency', 'created_by'];

    /** Participants the spends are split among (snapshot of the family at creation). */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'spending_event_user', 'spend_event_id', 'user_id');
    }

    /** Per-participant split settings (headcount + covered_by); rows exist only for non-defaults. */
    public function participantSettings(): HasMany
    {
        return $this->hasMany(SpendParticipant::class);
    }

    /**
     * Split settings for the balance math: every participant's key => headcount weight (>=1), and the
     * cover map (key => coverer key). Participants default to weight 1 and no cover, so events with no
     * `spending_participants` rows split equally among everyone exactly as before.
     *
     * @return array{weights: array<string,int>, coveredBy: array<string,string>}
     */
    public function splitSettings(): array
    {
        $names = $this->participantNames();
        $weights = array_fill_keys(array_keys($names), 1);
        $coveredBy = [];

        foreach ($this->participantSettings as $p) {
            if (! array_key_exists($p->participant_key, $weights)) {
                continue; // stale row for a participant no longer on the event
            }
            $weights[$p->participant_key] = max(1, $p->weight);
            // Only honour a cover pointing at a real, current participant.
            if ($p->covered_by && array_key_exists($p->covered_by, $names)) {
                $coveredBy[$p->participant_key] = $p->covered_by;
            }
        }

        return ['weights' => $weights, 'coveredBy' => $coveredBy];
    }

    /**
     * Every participant's balance key => display name, across family members ('u{id}') and guests
     * ('g{id}'). Namespaced so a user id and a guest id can never collide in the balance sheet.
     *
     * @return array<string, string>
     */
    public function participantNames(): array
    {
        // Split participants = family members who can actually reach the event (either `family`
        // visibility, or an explicit share) plus guests. A member whose access is revoked drops out of
        // the split — EXCEPT one who fronted money for a spend, who stays so their payment is still
        // attributed and can be settled. Mirrors ShareAccess::roleFor for members.
        $visibility = $this->visibility();
        $shared = $this->shares()->pluck('user_id')->all();
        $paidBy = $this->spends()->whereNotNull('payer_id')->distinct()->pluck('payer_id')->all();

        $names = [];
        foreach ($this->members()->get(['users.id', 'name']) as $m) {
            $hasAccess = $visibility === 'family' || in_array($m->id, $shared, true);
            if ($hasAccess || in_array($m->id, $paidBy, true)) {
                $names['u'.$m->id] = $m->name;
            }
        }
        foreach ($this->guests as $g) {
            $names['g'.$g->id] = $g->name;
        }

        return $names;
    }

    /**
     * Build the stored spend columns from validated input, freezing the FX conversion at the spend's
     * date. Payer + created_by are added by the caller (differs for user vs guest spends). Single
     * source of the money math so owner and guest write paths can never drift.
     */
    public function buildSpend(array $data): array
    {
        // Decimal major units in, integer minor units stored. round() so 48.00 -> 4800 exactly.
        $amount = (int) round($data['amount'] * 100);
        $baseAmount = Fx::convert($amount, $data['currency'], $this->currency, $data['spent_at']);

        return [
            'source_id' => $data['source_id'] ?? null,
            'amount' => $amount,
            'currency' => $data['currency'],
            'base_amount' => $baseAmount,
            'rate' => $amount ? $baseAmount / $amount : 1,
            'category' => $data['category'],
            'note' => $data['note'] ?? null,
            'spent_at' => $data['spent_at'],
        ];
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

    /**
     * Guest page (reached via the guest link, after any password gate). Shows the full split across
     * family members and guests; the client picks "who am I" from the guest list (remembered locally)
     * and gets the add-expense form only if that guest's role is editor. Writes post to the spending
     * guest routes, authorized server-side by GuestAccess.
     */
    public function guestView(): array
    {
        $spends = $this->spends()
            ->with(['payer:id,name', 'payerGuest:id,name', 'source:id,name,type', 'shares'])
            ->latest('spent_at')->latest('id')->get();
        $settlements = $this->settlements()->get();
        $balances = EventBalances::of($this, $spends, $settlements);

        return [
            'component' => 'spending/guest-event',
            'props' => [
                'token' => $this->guestLink?->token,
                'event' => [
                    'name' => $this->name,
                    'currency' => $this->currency,
                    'total' => (int) $spends->sum('base_amount'),
                    'categories' => SpendingController::CATEGORIES,
                    'currencies' => SpendingController::CURRENCIES,
                ],
                'guests' => $this->guests->map(fn ($g) => [
                    'id' => $g->id, 'name' => $g->name, 'role' => $g->role,
                ])->values(),
                'spends' => $spends->map(fn (Spend $s) => [
                    'id' => $s->id,
                    'amount' => $s->amount,
                    'currency' => $s->currency,
                    'base_amount' => $s->base_amount,
                    'category' => $s->category,
                    'note' => $s->note,
                    'payer' => ['name' => $s->payer?->name ?? $s->payerGuest?->name, 'guestId' => $s->payer_guest_id],
                    'spent_at' => $s->spent_at->toDateString(),
                ])->values(),
                'balances' => $balances['balances'],
                'suggestions' => $balances['suggestions'],
            ],
        ];
    }
}
