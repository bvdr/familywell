<?php

namespace Modules\ExpenseImport;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Translations;
use App\Support\TypeSafe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ExpenseImport\Statement\Formats;
use Modules\ExpenseImport\Statement\Table;
use Modules\Spending\SpendCategory;
use Modules\Spending\SpendingController;
use Throwable;

// Expense import pages: the module home (upload + waiting reviews + history), the review screen, the one-time
// column mapping for an unknown bank, and the module's settings (everything is switched on there).
class ExpenseImportController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = $request->user()->id;
        $settings = ImportSetting::current();

        $batches = ImportBatch::query()->where('user_id', $userId)
            ->withCount(['rows', 'rows as imported_count' => fn ($q) => $q->whereNotNull('spend_id')])
            ->latest('id')->limit(40)->get();

        return Inertia::render('expense_import/index', [
            'statementsEnabled' => $settings->statements_enabled,
            'shortcutEnabled' => $settings->shortcut_enabled,
            'pending' => $batches->whereIn('status', [ImportBatch::REVIEW, ImportBatch::MAPPING])->map(self::batchCard(...))->values(),
            'history' => $batches->whereIn('status', [ImportBatch::IMPORTED, ImportBatch::UNDONE])->map(self::batchCard(...))->values(),
        ]);
    }

    public function upload(Request $request): RedirectResponse
    {
        abort_unless(ImportSetting::current()->statements_enabled, 404);
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:csv,txt,xlsx']]);
        $file = $request->file('file');
        $name = $file->getClientOriginalName();

        try {
            $table = Table::read($file->getRealPath(), $name);
        } catch (Throwable $e) {
            Log::info('expense import: unreadable statement', ['error' => $e->getMessage()]);
            throw ValidationException::withMessages(['file' => Translations::get('expense_import.error_unreadable')]);
        }
        $found = Formats::detect($table);
        if (! $found) {
            throw ValidationException::withMessages(['file' => Translations::get('expense_import.error_no_table')]);
        }

        $batch = new ImportBatch(['family_id' => CurrentFamily::id(), 'user_id' => $request->user()->id, 'source' => 'statement', 'filename' => mb_substr($name, 0, 255)]);
        $mapping = $found['format'] ? null : ImportMapping::where('signature', $found['signature'])->first();

        if (! $found['format'] && ! $mapping) {
            // A bank we don't know yet: keep the table until its columns are mapped (once per family + bank).
            $batch->fill(['status' => ImportBatch::MAPPING, 'raw' => ['table' => $table, 'header' => $found['header'], 'signature' => $found['signature']]])->save();

            return redirect()->route('expense_import.map', $batch);
        }

        $transactions = $found['format']
            ? Formats::read($found['format'], $table, $found['header'])
            : Formats::readMapped($mapping->mapping, $table, $found['header']);
        if ($transactions === []) {
            throw ValidationException::withMessages(['file' => Translations::get('expense_import.error_empty')]);
        }

        $batch->fill(['status' => ImportBatch::REVIEW, 'bank' => $found['label'] ?? $mapping->label])->save();
        Importer::stage($batch, $transactions, Importer::fallbackCurrency(), $request->user());

        return redirect()->route('expense_import.batch', $batch);
    }

    public function map(Request $request, ImportBatch $batch): Response
    {
        $this->own($request, $batch);
        abort_unless($batch->status === ImportBatch::MAPPING, 404);
        $table = $batch->raw['table'];
        $header = $batch->raw['header'];

        return Inertia::render('expense_import/map', [
            'batch' => ['id' => $batch->id, 'filename' => $batch->filename],
            'headers' => $table[$header],
            'samples' => array_slice($table, $header + 1, 4),
            'currencies' => SpendingController::CURRENCIES,
            'defaultCurrency' => Importer::fallbackCurrency(),
        ]);
    }

    public function saveMapping(Request $request, ImportBatch $batch): RedirectResponse
    {
        $this->own($request, $batch);
        abort_unless($batch->status === ImportBatch::MAPPING, 404);
        $columns = count($batch->raw['table'][$batch->raw['header']]);
        $col = ['nullable', 'integer', 'min:0', 'max:'.($columns - 1)];
        $data = $request->validate([
            'label' => ['required', 'string', 'max:60'],
            'date' => ['required', 'integer', 'min:0', 'max:'.($columns - 1)],
            'merchant' => ['required', 'integer', 'min:0', 'max:'.($columns - 1)],
            'amount' => [...$col, 'required_without_all:debit,credit'],
            'debit' => $col,
            'credit' => $col,
            'currency' => $col,
            'reference' => $col,
            'fixed_currency' => ['nullable', Rule::in(SpendingController::CURRENCIES)],
            'expenses_positive' => ['boolean'],
        ]);

        $mapping = ImportMapping::updateOrCreate(['signature' => $batch->raw['signature']], ['label' => $data['label'], 'mapping' => $data]);
        $transactions = Formats::readMapped($mapping->mapping, $batch->raw['table'], $batch->raw['header']);
        if ($transactions === []) {
            throw ValidationException::withMessages(['date' => Translations::get('expense_import.error_mapping_empty')]);
        }

        $batch->update(['status' => ImportBatch::REVIEW, 'bank' => $data['label'], 'raw' => null]);
        Importer::stage($batch, $transactions, $data['fixed_currency'] ?? Importer::fallbackCurrency(), $request->user());

        return redirect()->route('expense_import.batch', $batch);
    }

    public function show(Request $request, ImportBatch $batch): Response|RedirectResponse
    {
        $this->own($request, $batch);
        if ($batch->status === ImportBatch::MAPPING) {
            return redirect()->route('expense_import.map', $batch);
        }
        $user = $request->user();
        [$event, $payer] = Importer::defaults($user, $batch->event_id, $batch->payer_key);

        return Inertia::render('expense_import/review', [
            'batch' => [...self::batchCard($batch->loadCount(['rows', 'rows as imported_count' => fn ($q) => $q->whereNotNull('spend_id')])), 'eventId' => $event->id, 'payerKey' => $payer],
            'rows' => $batch->rows->map(fn (ImportRow $r) => [
                'id' => $r->id,
                'date' => $r->spent_at->toDateString(),
                'merchant' => $r->merchant,
                'amount' => $r->amount,
                'currency' => $r->currency,
                'kind' => $r->kind,
                'flag' => $r->flag,
                'selected' => $r->selected,
                'category' => $r->category,
                'imported' => $r->spend_id !== null,
            ]),
            'lists' => Importer::editableLists($user),
            'members' => Importer::members(),
            'categories' => SpendCategory::forFamily()->map(fn (SpendCategory $c) => $c->toPayload())->values(),
            'aiCategorize' => TypeSafe::configured(),
        ]);
    }

    // The review screen calls this in a loop (5 merchants per call) until nothing is left, like Spending's
    // "sort Other" tool, so a long statement never makes one slow request.
    public function suggest(Request $request, ImportBatch $batch): JsonResponse
    {
        $this->own($request, $batch);
        abort_unless(TypeSafe::configured() && $batch->status === ImportBatch::REVIEW, 404);
        $remaining = Importer::suggest($batch);

        return response()->json([
            'remaining' => $remaining,
            'categories' => $batch->rows()->pluck('category', 'id'),
        ]);
    }

    public function import(Request $request, ImportBatch $batch): RedirectResponse
    {
        $this->own($request, $batch);
        abort_unless($batch->status === ImportBatch::REVIEW, 404);
        $data = $request->validate([
            'event_id' => ['required', 'integer'],
            'payer_key' => ['required', 'string', 'max:20'],
            'rows' => ['array'],
            'rows.*.id' => ['required', 'integer'],
            'rows.*.selected' => ['required', 'boolean'],
            'rows.*.category' => ['nullable', 'string', 'max:40'],
        ]);

        [$event, $payer] = Importer::defaults($request->user(), (int) $data['event_id'], $data['payer_key']);
        if ($event->id !== (int) $data['event_id']) {
            throw ValidationException::withMessages(['event_id' => Translations::get('expense_import.error_list')]);
        }
        $choices = collect($data['rows'] ?? [])->keyBy('id')->all();
        Importer::commit($batch, $request->user(), $event, $payer, $choices);

        return redirect()->route('expense_import.batch', $batch);
    }

    public function undo(Request $request, ImportBatch $batch): RedirectResponse
    {
        $this->own($request, $batch);
        abort_unless($batch->status === ImportBatch::IMPORTED, 404);
        Importer::undo($batch);

        return back();
    }

    public function destroy(Request $request, ImportBatch $batch): RedirectResponse
    {
        $this->own($request, $batch);
        abort_unless(in_array($batch->status, [ImportBatch::REVIEW, ImportBatch::MAPPING], true), 404);
        $batch->delete(); // nothing was saved yet; its rows go with it

        return redirect()->route('expense_import.index');
    }

    public function settings(Request $request): Response
    {
        $s = ImportSetting::current();
        $family = Family::findOrFail(CurrentFamily::id());

        return Inertia::render('expense_import/settings', [
            'settings' => [
                'statementsEnabled' => $s->statements_enabled,
                'shortcutEnabled' => $s->shortcut_enabled,
                'defaultEventId' => $s->default_event_id,
                'defaultPayerKey' => $s->default_payer_key,
                'autoSave' => $s->auto_save,
            ],
            'lists' => Importer::editableLists($request->user()),
            'members' => Importer::members(),
            'canManage' => $request->user()->can('manageMembers', $family),
            // This person's own Apple Pay link (each member has their own) + the ready-made Shortcut to install.
            'payUrl' => ImportToken::urlFor($request->user()->id),
            'shortcutUrl' => config('services.expense_import.shortcut_url') ?: null,
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $this->authorize('manageMembers', Family::findOrFail(CurrentFamily::id()));
        $memberKeys = array_column(Importer::members(), 'key');
        $data = $request->validate([
            'statements_enabled' => ['sometimes', 'boolean'],
            'shortcut_enabled' => ['sometimes', 'boolean'],
            'auto_save' => ['sometimes', 'boolean'],
            'default_event_id' => ['sometimes', 'nullable', 'integer', Rule::in(Importer::editableLists($request->user())->pluck('id')->all() ?: [0])],
            'default_payer_key' => ['sometimes', 'nullable', 'string', Rule::in($memberKeys)],
        ]);
        ImportSetting::current()->update($data);

        return back();
    }

    // Generate (or rotate) this person's Apple Pay link. Any member may have one; it adds expenses as them.
    public function createToken(Request $request): RedirectResponse
    {
        abort_unless(ImportSetting::current()->shortcut_enabled, 404);
        ImportToken::rotate($request->user()->id);

        return back();
    }

    public function revokeToken(Request $request): RedirectResponse
    {
        ImportToken::where('user_id', $request->user()->id)->delete();

        return back();
    }

    /** Batches are private to whoever imported them (a statement is personal); anyone else gets a 404. */
    private function own(Request $request, ImportBatch $batch): void
    {
        abort_unless($batch->user_id === $request->user()->id && $batch->family_id === (int) CurrentFamily::id(), 404);
    }

    private static function batchCard(ImportBatch $b): array
    {
        return [
            'id' => $b->id,
            'source' => $b->source,
            'status' => $b->status,
            'bank' => $b->bank,
            'filename' => $b->filename,
            'rows' => (int) ($b->rows_count ?? 0),
            'imported' => (int) ($b->imported_count ?? 0),
            'createdAt' => $b->created_at?->toIso8601String(),
            'importedAt' => $b->imported_at?->toIso8601String(),
        ];
    }
}
