<?php

namespace Modules\Spending;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SpendSettlement extends Model
{
    use BelongsToFamily;
    use SoftDeletes; // deleted transfers sit in the 30-day trash (SpendingTrash), like expenses

    protected $table = 'spending_settlements';

    // from_key/to_key are namespaced participant keys ('u{id}' member / 'g{id}' guest), so a transfer
    // can involve a guest. Matches Spend::participantKey() and SpendShare.participant_key.
    protected $fillable = ['spend_event_id', 'from_key', 'to_key', 'amount', 'note', 'created_by'];

    protected $casts = ['amount' => 'integer'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(SpendEvent::class, 'spend_event_id');
    }

    /**
     * Same payer, payee and amount already recorded on this list in the last 24 hours. Backs the soft
     * "already recorded?" warning on every write path (member + guest Transfers forms, edits). Why: on
     * 2026-10-12 a transfer's save committed but never confirmed on the phone, so it was re-entered 16s
     * later and recorded twice (list 1896, transfers 11 + 12). Transfers have no date of their own, so
     * the window is by created_at. Soft, never blocking: paying someone the same amount twice is real.
     */
    public static function looksDuplicate(SpendEvent $event, string $from, string $to, int $amount, ?int $exceptId = null): bool
    {
        return $event->settlements()
            ->where(['from_key' => $from, 'to_key' => $to, 'amount' => $amount])
            ->where('created_at', '>=', now()->subDay())
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();
    }
}
