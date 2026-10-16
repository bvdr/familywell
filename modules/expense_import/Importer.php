<?php

namespace Modules\ExpenseImport;

use App\Events\SpendingChanged;
use App\Models\Family;
use App\Models\User;
use App\Support\Realtime;
use App\Support\Sharing\ShareAccess;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\ExpenseImport\Statement\Values;
use Modules\Spending\Spend;
use Modules\Spending\SpendCategorizer;
use Modules\Spending\SpendCategory;
use Modules\Spending\SpendEvent;
use Modules\Spending\SpendingController;
use Modules\Spending\SpendSetting;

// The import logic, shared by statement uploads and the Apple Pay link. Writes go through Spending's own
// create path pieces (SpendEvent::spendRules() validation + spendColumns() money math + spends()->create(),
// exactly what the spending_add_expense MCP tool does), so an imported expense is indistinguishable from a
// typed one and Spending itself is untouched. Spend::created then queues the AI category guess for rows
// still in "other", as for any expense.
class Importer
{
    /**
     * Turn parsed transactions into review rows on $batch: fingerprint, kind, duplicate/already-imported
     * flags (unticked) and a category from the family's history.
     *
     * @param  list<array<string, mixed>>  $transactions  see Statement\Formats
     */
    public static function stage(ImportBatch $batch, array $transactions, string $fallbackCurrency, ?User $viewer = null): void
    {
        $seenInFile = [];
        $rows = [];
        foreach ($transactions as $tx) {
            $currency = $tx['currency'] ?? $fallbackCurrency;
            $merchant = trim((string) $tx['merchant']) !== '' ? $tx['merchant'] : '—';
            // Remember-key: the bank's own id when it has one, else date+amount+merchant. Two identical coffees
            // on one day are both real, so the Nth repeat inside one file gets its own suffix.
            $base = $tx['reference'] ? 'ref|'.$batch->bank.'|'.$tx['reference'] : implode('|', [$tx['date'], $tx['amount'], $currency, Values::key($merchant)]);
            $n = $seenInFile[$base] = ($seenInFile[$base] ?? 0) + 1;
            $rows[] = [
                'spent_at' => $tx['date'],
                'amount' => abs((int) $tx['amount']),
                'currency' => $currency,
                'merchant' => mb_substr($merchant, 0, 190),
                'reference' => $tx['reference'] ?? null,
                'fingerprint' => sha1($base.($n > 1 ? "#$n" : '')),
                'kind' => $tx['kind'],
            ];
        }

        $imported = self::importedFingerprints(array_column($rows, 'fingerprint'));
        $history = self::history();
        $duplicates = self::duplicateFinder($rows, $viewer ?? User::find($batch->user_id));

        foreach ($rows as $r) {
            $flag = match (true) {
                isset($imported[$r['fingerprint']]) => 'imported',
                $r['kind'] === 'expense' && $duplicates($r) => 'duplicate',
                ! in_array($r['currency'], SpendingController::CURRENCIES, true) => 'currency',
                default => null,
            };
            $batch->rows()->create([
                ...$r,
                'family_id' => $batch->family_id,
                'flag' => $flag,
                // Only plain expenses come in ticked: income, refunds, own-account transfers and anything
                // flagged wait for a deliberate tick.
                'selected' => $r['kind'] === 'expense' && $flag === null,
                'category' => $r['kind'] === 'expense' ? self::guess($r['merchant'], $history) : null,
            ]);
        }
    }

    /**
     * Save the ticked rows of a reviewed batch as Spending expenses. Returns [created count, rows that could
     * not be saved]. The batch becomes undoable as a whole.
     *
     * @param  array<int, array{selected?: bool, category?: string|null}>  $choices  row id => edits from the review screen
     * @return array{0: int, 1: list<int>}
     */
    public static function commit(ImportBatch $batch, User $user, SpendEvent $event, string $payerKey, array $choices): array
    {
        $created = 0;
        $failed = [];
        $categories = SpendCategory::keysFor();

        DB::transaction(function () use ($batch, $user, $event, $payerKey, $choices, $categories, &$created, &$failed) {
            $rules = $event->spendRules();
            foreach ($batch->rows()->whereNull('spend_id')->get() as $row) {
                $choice = $choices[$row->id] ?? [];
                $selected = (bool) ($choice['selected'] ?? $row->selected);
                $category = $choice['category'] ?? $row->category;
                $row->fill(['selected' => $selected, 'category' => in_array($category, $categories, true) ? $category : $row->category]);
                if (! $selected) {
                    $row->save();

                    continue;
                }
                $data = [
                    'amount' => $row->amount / 100,
                    'currency' => $row->currency,
                    'category' => $row->category ?? 'other',
                    'payer_key' => $payerKey,
                    'spent_at' => $row->spent_at->toDateString(),
                    'note' => mb_substr($row->merchant, 0, 255),
                    'store' => mb_substr($row->merchant, 0, 120),
                ];
                $v = Validator::make($data, $rules);
                if ($v->fails()) {
                    $failed[] = $row->id; // e.g. a currency Spending doesn't support; left for the person to see
                    $row->save();

                    continue;
                }
                $spend = $event->spends()->create([...$event->spendColumns($v->validated()), 'created_by' => $user->id]);
                $row->spend_id = $spend->id;
                $row->save();
                $created++;
            }

            $batch->update(['status' => ImportBatch::IMPORTED, 'imported_at' => now(), 'event_id' => $event->id, 'payer_key' => $payerKey]);
        });

        if ($created) {
            Realtime::broadcast(new SpendingChanged((int) $batch->family_id));
        }

        return [$created, $failed];
    }

    /** Undo a whole import: its expenses go to the 30-day trash and its transactions may be imported again. */
    public static function undo(ImportBatch $batch): int
    {
        $ids = $batch->rows()->whereNotNull('spend_id')->pluck('spend_id');
        $spends = Spend::whereIn('id', $ids)->get();
        $spends->each->delete(); // soft delete = Spending's own trash, restorable one by one
        $batch->update(['status' => ImportBatch::UNDONE, 'undone_at' => now()]);
        if ($spends->isNotEmpty()) {
            Realtime::broadcast(new SpendingChanged((int) $batch->family_id));
        }

        return $spends->count();
    }

    /**
     * One Apple Pay payment from the Shortcut. Auto-save on: becomes an expense right away (in a one-row batch,
     * so it can be undone and is remembered). Off: joins this person's review queue. Returns [status, batch].
     *
     * @return array{0: string, 1: ImportBatch}
     */
    public static function payment(User $user, int $cents, string $currency, string $merchant, string $date): array
    {
        $settings = ImportSetting::current();
        $tx = ['date' => $date, 'amount' => -$cents, 'currency' => $currency, 'merchant' => $merchant, 'reference' => null, 'kind' => 'expense'];

        // Apple Pay can fire twice for one tap (watch + phone, retries): same amount + merchant within 10
        // minutes is the same payment.
        $key = Values::key($merchant);
        $recent = ImportRow::query()
            ->whereHas('batch', fn ($q) => $q->where('user_id', $user->id)->where('source', 'shortcut'))
            ->where('amount', $cents)->where('currency', $currency)->where('created_at', '>=', now()->subMinutes(10))
            ->get()->first(fn (ImportRow $r) => Values::key($r->merchant) === $key);
        if ($recent) {
            return ['repeat', $recent->batch];
        }

        if (! $settings->auto_save) {
            $batch = ImportBatch::firstOrCreate(
                ['user_id' => $user->id, 'source' => 'shortcut', 'status' => ImportBatch::REVIEW],
                ['family_id' => CurrentFamily::id(), 'bank' => 'Apple Pay'],
            );
            self::stage($batch, [$tx], $currency, $user);

            return ['queued', $batch];
        }

        $batch = ImportBatch::create(['family_id' => CurrentFamily::id(), 'user_id' => $user->id, 'source' => 'shortcut', 'status' => ImportBatch::REVIEW, 'bank' => 'Apple Pay']);
        self::stage($batch, [$tx], $currency, $user);
        [$event, $payer] = self::defaults($user);
        // Auto-save trusts the payment: a "possible duplicate" flag doesn't hold it back, the row is ticked.
        $batch->rows()->update(['selected' => true]);
        [$created] = self::commit($batch, $user, $event, $payer, []);

        return [$created ? 'saved' : 'failed', $batch->fresh()];
    }

    /**
     * Where an import goes unless the person picks otherwise: the family's default list if this person can
     * add to it, else Spending's "Others" list; paid by the default payer if they take part in that list,
     * else by the person importing.
     *
     * @return array{0: SpendEvent, 1: string}
     */
    public static function defaults(User $user, ?int $eventId = null, ?string $payerKey = null): array
    {
        $settings = ImportSetting::current();
        $access = app(ShareAccess::class);
        $event = collect([$eventId, $settings->default_event_id])->filter()
            ->map(fn ($id) => SpendEvent::whereNull('archived_at')->find($id))
            ->first(fn (?SpendEvent $e) => $e && $access->canEdit($user, $e))
            ?? SpendEvent::others($user);

        $participants = $event->participantNames();
        $payer = collect([$payerKey, $settings->default_payer_key, 'u'.$user->id])->first(fn ($k) => $k && isset($participants[$k])) ?? 'u'.$user->id;

        return [$event, $payer];
    }

    /** The family's own habit for a shop: its most used category, matched on the full name, then the first word. */
    public static function guess(string $merchant, array $history): ?string
    {
        $key = Values::key($merchant);
        if ($key === '') {
            return null;
        }

        return $history['full'][$key] ?? $history['word'][strtok($key, ' ')] ?? null;
    }

    /**
     * Learned shop -> category from the family's past expenses (store, else note) and earlier imports.
     * "Other" teaches nothing. First words shorter than 3 letters are too vague to match on.
     *
     * @return array{full: array<string, string>, word: array<string, string>}
     */
    public static function history(): array
    {
        $votes = ['full' => [], 'word' => []];
        $add = function (?string $name, string $category, int $count) use (&$votes) {
            $key = Values::key((string) $name);
            if ($key === '' || $category === 'other') {
                return;
            }
            $votes['full'][$key][$category] = ($votes['full'][$key][$category] ?? 0) + $count;
            $word = strtok($key, ' ');
            if (strlen($word) >= 3 && ! ctype_digit($word)) {
                $votes['word'][$word][$category] = ($votes['word'][$word][$category] ?? 0) + $count;
            }
        };

        Spend::query()->selectRaw('COALESCE(store, note) as name, category, count(*) as c')
            ->where('category', '!=', 'other')->whereRaw('COALESCE(store, note) IS NOT NULL')
            ->groupByRaw('COALESCE(store, note), category')->orderByDesc('c')->limit(2000)->get()
            ->each(fn ($r) => $add($r->name, $r->category, (int) $r->c));

        $pick = fn (array $byKey) => array_map(fn (array $cats) => array_search(max($cats), $cats, true), $byKey);

        return ['full' => $pick($votes['full']), 'word' => $pick($votes['word'])];
    }

    /**
     * Ask the AI categoriser (TypeSafe/Jev, as Spending's own tools do) for up to $limit undecided merchants of
     * a batch; the answer is applied to every row with that merchant. Returns how many rows still wait.
     */
    public static function suggest(ImportBatch $batch, int $limit = SpendCategorizer::BATCH): int
    {
        $pending = fn () => $batch->rows()->where('kind', 'expense')->whereNull('category')->where('ai_tried', false);
        $criteria = SpendCategorizer::criteria(SpendCategory::forFamily());

        foreach ($pending()->get()->unique(fn (ImportRow $r) => Values::key($r->merchant))->take($limit) as $row) {
            $key = SpendCategorizer::suggest([
                'note' => $row->merchant,
                'amount' => number_format($row->amount / 100, 2, '.', '').' '.$row->currency,
                'date' => $row->spent_at->toDateString(),
            ], $criteria);
            $same = $batch->rows()->whereNull('category')->get()->filter(fn (ImportRow $r) => Values::key($r->merchant) === Values::key($row->merchant))->pluck('id');
            ImportRow::whereIn('id', $same)->update(['ai_tried' => true, 'category' => $key]);
        }

        return $pending()->count();
    }

    /** Fingerprints that already became an expense in an import that wasn't undone. */
    private static function importedFingerprints(array $fingerprints): array
    {
        return ImportRow::query()->whereIn('fingerprint', $fingerprints)->whereNotNull('spend_id')
            ->whereHas('batch', fn ($q) => $q->where('status', ImportBatch::IMPORTED))
            ->pluck('fingerprint')->flip()->all();
    }

    /**
     * A matcher for "this is already in Spending": same amount and currency, within a day, and a similar shop
     * (an expense typed without a shop counts as similar). Loads the candidate expenses once per upload.
     */
    private static function duplicateFinder(array $rows, ?User $viewer): callable
    {
        $expenses = array_filter($rows, fn ($r) => $r['kind'] === 'expense');
        if ($expenses === [] || ! $viewer) {
            return fn () => false;
        }
        $dates = array_column($expenses, 'spent_at');
        $visible = SpendEvent::query()->visibleTo($viewer->id)->pluck('id');
        $candidates = Spend::query()->whereIn('spend_event_id', $visible)
            ->whereBetween('spent_at', [Carbon::parse(min($dates))->subDay()->toDateString(), Carbon::parse(max($dates))->addDay()->toDateString()])
            ->whereIn('amount', array_column($expenses, 'amount'))
            ->get(['amount', 'currency', 'spent_at', 'store', 'note']);

        return function (array $r) use ($candidates): bool {
            $word = strtok(Values::key($r['merchant']), ' ') ?: '';

            return $candidates->contains(function (Spend $s) use ($r, $word) {
                if ($s->amount !== $r['amount'] || $s->currency !== $r['currency'] || abs($s->spent_at->diffInDays(Carbon::parse($r['spent_at']))) > 1) {
                    return false;
                }
                $theirs = Values::key((string) ($s->store ?? $s->note ?? ''));

                return $theirs === '' || $word === '' || str_contains($theirs, $word) || str_contains(Values::key($r['merchant']), strtok($theirs, ' '));
            });
        };
    }

    /** The currency a statement without one is in: the family's Spending default, else its "Others" list's, else EUR. */
    public static function fallbackCurrency(): string
    {
        return SpendSetting::defaultCurrency() ?? SpendEvent::whereNull('archived_at')->orderBy('id')->value('currency') ?? 'EUR';
    }

    /** @return Collection<int, array{id: int, name: string, currency: string}> lists this person can add expenses to */
    public static function editableLists(User $user): Collection
    {
        $access = app(ShareAccess::class);

        return SpendEvent::query()->whereNull('archived_at')->orderBy('name')->get()
            ->filter(fn (SpendEvent $e) => $access->canEdit($user, $e))
            ->map(fn (SpendEvent $e) => ['id' => $e->id, 'name' => $e->name, 'currency' => $e->currency])->values();
    }

    /** @return list<array{key: string, name: string}> family members as payer keys */
    public static function members(): array
    {
        return Family::findOrFail(CurrentFamily::id())->members()->orderBy('name')->get(['users.id', 'name'])
            ->map(fn (User $u) => ['key' => 'u'.$u->id, 'name' => $u->name])->values()->all();
    }
}
