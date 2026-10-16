<?php

use Illuminate\Support\Facades\Http;
use Modules\ExpenseImport\ImportBatch;
use Modules\ExpenseImport\Importer;
use Modules\ExpenseImport\ImportRow;
use Modules\ExpenseImport\ImportSetting;
use Modules\Spending\Spend;
use Modules\Spending\SpendEvent;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/helpers.php';

// Statement import end to end: upload -> review rows (flags, ticks, categories) -> import into Spending ->
// remembered next time -> undo. Expenses are written through Spending's own create path.

function uploadWise($user)
{
    return actingAs($user)->post('/m/expense-import/statements', ['file' => statementFile('wise.csv', wiseCsv())]);
}

it('stages a statement for review: plain expenses ticked, income/transfers/duplicates not, categories from history', function () {
    [, $users] = importFamily();
    $home = SpendEvent::createFor($users[0], ['name' => 'Home', 'currency' => 'EUR']);
    // History: the family files Lidl under food (twice, so it's a habit). And the café coffee is already in Spending.
    foreach (['2026-09-01', '2026-09-08'] as $d) {
        $home->spends()->create(['payer_id' => $users[0]->id, ...$home->buildSpend(['amount' => 30, 'currency' => 'EUR', 'category' => 'food', 'spent_at' => $d, 'store' => 'Lidl']), 'created_by' => $users[0]->id]);
    }
    $home->spends()->create(['payer_id' => $users[0]->id, ...$home->buildSpend(['amount' => 12, 'currency' => 'EUR', 'category' => 'food', 'spent_at' => '2026-10-06', 'note' => 'Coffee', 'store' => 'Cafe Verde']), 'created_by' => $users[0]->id]);

    $batch = null;
    uploadWise($users[0])->assertRedirect()->assertSessionHasNoErrors();
    $batch = ImportBatch::sole();
    expect($batch->status)->toBe('review')->and($batch->bank)->toBe('Wise');

    $rows = $batch->rows->keyBy('reference');
    expect($rows['CARD-1001'])->toMatchArray(['kind' => 'expense', 'selected' => true, 'flag' => null, 'category' => 'food', 'amount' => 4520])
        ->and($rows['CARD-1002'])->toMatchArray(['flag' => 'duplicate', 'selected' => false])
        ->and($rows['TRANSFER-77'])->toMatchArray(['kind' => 'income', 'selected' => false])
        ->and($rows['BALANCE-9'])->toMatchArray(['kind' => 'transfer', 'selected' => false]);

    actingAs($users[0])->get("/m/expense-import/batches/{$batch->id}")->assertInertia(fn ($p) => $p
        ->component('expense_import/review')->has('rows', 4)->where('batch.status', 'review')->etc());
});

it('imports the ticked rows as Spending expenses, remembers them, and undo sends them to the trash', function () {
    [, $users] = importFamily();
    $home = SpendEvent::createFor($users[0], ['name' => 'Home', 'currency' => 'EUR']);
    uploadWise($users[0]);
    $batch = ImportBatch::sole();
    $lidl = $batch->rows()->where('reference', 'CARD-1001')->first();
    $cafe = $batch->rows()->where('reference', 'CARD-1002')->first();

    actingAs($users[0])->post("/m/expense-import/batches/{$batch->id}/import", [
        'event_id' => $home->id,
        'payer_key' => 'u'.$users[1]->id,
        'rows' => [
            ['id' => $lidl->id, 'selected' => true, 'category' => 'food'],
            ['id' => $cafe->id, 'selected' => true, 'category' => 'activities'],
        ],
    ])->assertRedirect("/m/expense-import/batches/{$batch->id}");

    $spends = Spend::orderBy('id')->get();
    expect($spends)->toHaveCount(2)
        ->and($spends[0])->toMatchArray(['spend_event_id' => $home->id, 'payer_id' => $users[1]->id, 'amount' => 4520, 'currency' => 'EUR', 'category' => 'food', 'store' => 'Lidl Bucuresti', 'created_by' => $users[0]->id])
        ->and($spends[0]->spent_at->toDateString())->toBe('2026-10-05')
        ->and($spends[1]->category)->toBe('activities')
        ->and($batch->fresh()->status)->toBe('imported');

    // The same statement again: those two are remembered and come in unticked as "already imported".
    uploadWise($users[0]);
    $again = ImportBatch::latest('id')->first();
    expect($again->rows()->where('flag', 'imported')->count())->toBe(2)
        ->and($again->rows()->where('selected', true)->count())->toBe(0);

    // Undo: both expenses to the trash (restorable), and the transactions can be imported again.
    actingAs($users[0])->post("/m/expense-import/batches/{$batch->id}/undo")->assertRedirect();
    expect(Spend::count())->toBe(0)->and(Spend::onlyTrashed()->count())->toBe(2)
        ->and($batch->fresh()->status)->toBe('undone');
    uploadWise($users[0]);
    expect(ImportBatch::latest('id')->first()->rows()->where('flag', 'imported')->count())->toBe(0);
});

it('falls back to the Others list and the importer when no list is chosen or the default is not allowed', function () {
    [, $users] = importFamily(['default_payer_key' => null]);
    uploadWise($users[1]);
    $batch = ImportBatch::sole();

    actingAs($users[1])->get("/m/expense-import/batches/{$batch->id}")->assertInertia(fn ($p) => $p
        ->where('batch.payerKey', 'u'.$users[1]->id)
        ->where('batch.eventId', SpendEvent::where('name', 'Others')->value('id'))->etc());
});

it('refuses a list the importer cannot add to', function () {
    [, $users] = importFamily();
    $private = SpendEvent::createFor($users[0], ['name' => 'Private', 'currency' => 'EUR']);
    $private->setVisibility('private');
    uploadWise($users[1]);
    $batch = ImportBatch::sole();

    actingAs($users[1])->post("/m/expense-import/batches/{$batch->id}/import", ['event_id' => $private->id, 'payer_key' => 'u'.$users[1]->id, 'rows' => []])
        ->assertSessionHasErrors('event_id');
    expect(Spend::count())->toBe(0);
});

it('keeps imports private to the person who uploaded them', function () {
    [, $users] = importFamily();
    uploadWise($users[0]);
    $batch = ImportBatch::sole();

    actingAs($users[1])->get("/m/expense-import/batches/{$batch->id}")->assertNotFound();
    actingAs($users[1])->post("/m/expense-import/batches/{$batch->id}/import", ['event_id' => 1, 'payer_key' => 'u1'])->assertNotFound();
    actingAs($users[1])->get('/m/expense-import')->assertInertia(fn ($p) => $p->has('pending', 0)->etc());
    actingAs($users[0])->get('/m/expense-import')->assertInertia(fn ($p) => $p->has('pending', 1)->etc());
});

it('asks once for the columns of an unknown bank and remembers them for the next statement', function () {
    [, $users] = importFamily();
    $csv = "Booking day;Shop name;Value;Note\n01.10.2026;Profi City;-23,40;card\n02.10.2026;Salary;3000,00;in\n";

    actingAs($users[0])->post('/m/expense-import/statements', ['file' => statementFile('cec.csv', $csv)])
        ->assertRedirect();
    $batch = ImportBatch::sole();
    expect($batch->status)->toBe('mapping');
    actingAs($users[0])->get("/m/expense-import/batches/{$batch->id}/map")->assertInertia(fn ($p) => $p
        ->component('expense_import/map')->where('headers', ['Booking day', 'Shop name', 'Value', 'Note'])->etc());

    actingAs($users[0])->post("/m/expense-import/batches/{$batch->id}/map", [
        'label' => 'CEC', 'date' => 0, 'merchant' => 1, 'amount' => 2, 'fixed_currency' => 'RON', 'expenses_positive' => false,
    ])->assertRedirect("/m/expense-import/batches/{$batch->id}");
    $batch->refresh();
    expect($batch->status)->toBe('review')->and($batch->bank)->toBe('CEC')->and($batch->raw)->toBeNull()
        ->and($batch->rows->pluck('kind', 'merchant')->all())->toBe(['Profi City' => 'expense', 'Salary' => 'income'])
        ->and($batch->rows->first()->currency)->toBe('RON');

    // Same layout again: straight to review, labelled with the bank name given the first time.
    actingAs($users[0])->post('/m/expense-import/statements', ['file' => statementFile('cec-nov.csv', $csv)]);
    expect(ImportBatch::latest('id')->first())->status->toBe('review')->bank->toBe('CEC');
});

it('turns the statement upload off with the setting, and rejects files that are not statements', function () {
    [, $users] = importFamily(['statements_enabled' => false]);
    uploadWise($users[0])->assertNotFound();

    ImportSetting::current()->update(['statements_enabled' => true]);
    actingAs($users[0])->post('/m/expense-import/statements', ['file' => statementFile('x.csv', "hello\n1\n")])
        ->assertSessionHasErrors('file');
});

it('suggests categories for unknown shops with the AI, a few merchants per call', function () {
    [, $users] = importFamily();
    config(['services.typesafe.key' => 'test-key']);
    Http::fake(['api.typesafe.ai/*' => Http::response(['answers' => ['category' => ['type' => 'choice', 'choice' => 'food', 'probabilities' => ['food' => 0.9, 'other' => 0.05], 'confidence' => 0.9]]])]);
    uploadWise($users[0]);
    $batch = ImportBatch::sole();
    expect($batch->rows()->where('kind', 'expense')->whereNull('category')->count())->toBe(2); // no history yet

    actingAs($users[0])->postJson("/m/expense-import/batches/{$batch->id}/suggest")->assertOk()->assertJson(['remaining' => 0]);
    expect($batch->rows()->where('kind', 'expense')->pluck('category')->unique()->all())->toBe(['food']);
    Http::assertSentCount(2); // one per distinct merchant, none for income/transfers
});

it('does not import a row twice when the review is submitted again', function () {
    [, $users] = importFamily();
    uploadWise($users[0]);
    $batch = ImportBatch::sole();
    $event = SpendEvent::others($users[0]);
    actingAs($users[0])->post("/m/expense-import/batches/{$batch->id}/import", ['event_id' => $event->id, 'payer_key' => 'u'.$users[0]->id, 'rows' => []]);
    actingAs($users[0])->post("/m/expense-import/batches/{$batch->id}/import", ['event_id' => $event->id, 'payer_key' => 'u'.$users[0]->id, 'rows' => []])->assertNotFound();
    expect(Spend::count())->toBe(ImportRow::whereNotNull('spend_id')->count())->toBe(2); // the two ticked card payments, once
});

it('reuses a shop\'s category only when the family\'s history makes it a habit', function () {
    [, $users] = importFamily();
    $home = SpendEvent::createFor($users[0], ['name' => 'Home', 'currency' => 'EUR']);
    $spend = fn (string $store, string $category) => $home->spends()->create(['payer_id' => $users[0]->id, ...$home->buildSpend(['amount' => 10, 'currency' => 'EUR', 'category' => $category, 'spent_at' => '2026-09-01', 'store' => $store]), 'created_by' => $users[0]->id]);

    $spend('Target', 'education');               // one purchase: not a habit (a general store)
    $spend('Emag', 'shopping');
    $spend('Emag', 'education');
    $spend('Emag', 'health'); // spread out: no habit
    $spend('Lidl', 'food');
    $spend('Lidl', 'food');
    $spend('Lidl', 'shopping');         // 2 of 3: a habit
    $history = Importer::history();

    expect(Importer::guess('TARGET 1234 CHICAGO', $history))->toBeNull()
        ->and(Importer::guess('Emag', $history))->toBeNull()
        ->and(Importer::guess('Lidl Bucuresti', $history))->toBe('food');
});
