<?php

use App\Support\Modules\ModuleRegistry;
use Modules\ExpenseImport\ImportBatch;
use Modules\ExpenseImport\ImportSetting;
use Modules\ExpenseImport\ImportToken;
use Modules\Spending\Spend;
use Modules\Spending\SpendEvent;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

require_once __DIR__.'/helpers.php';

// The Apple Pay link: an iPhone Shortcut posts each payment (amount + merchant) to a personal token URL.

function payUrl($user): string
{
    actingAs($user)->post('/m/expense-import/settings/token')->assertRedirect();

    return '/expense-import/pay/'.ImportToken::where('user_id', $user->id)->value('token');
}

it('queues a payment for review by default, reading the amount the way Shortcuts sends it', function () {
    [, $users] = importFamily();
    $url = payUrl($users[1]);
    auth()->logout();

    postJson($url, ['amount' => '50,00 RON', 'merchant' => 'Mega Image'])
        ->assertOk()->assertSeeText('waiting for review');

    $batch = ImportBatch::sole();
    expect($batch)->source->toBe('shortcut')->status->toBe('review')->user_id->toBe($users[1]->id)
        ->and($batch->rows->first())->amount->toBe(5000)->currency->toBe('RON')->merchant->toBe('Mega Image')->selected->toBeTrue()
        ->and(Spend::count())->toBe(0);

    // A second payment joins the same queue; the same one fired twice (phone + watch) is ignored.
    postJson($url, ['amount' => '€12.50', 'merchant' => 'Cafe Verde'])->assertOk();
    postJson($url, ['amount' => '€12.50', 'merchant' => 'Cafe Verde'])->assertOk()->assertSeeText('Already got');
    expect(ImportBatch::count())->toBe(1)->and($batch->rows()->count())->toBe(2);
});

it('saves a payment straight to Spending with auto-save on, into the default list, undoable', function () {
    [, $users] = importFamily(['auto_save' => true]);
    $home = SpendEvent::createFor($users[0], ['name' => 'Home', 'currency' => 'EUR']);
    ImportSetting::current()->update(['default_event_id' => $home->id]);
    $url = payUrl($users[1]);
    auth()->logout();

    post($url, ['amount' => '23.40', 'merchant' => 'Lidl'])->assertOk()->assertSeeText('Added 23.40 EUR');

    $spend = Spend::sole();
    expect($spend)->spend_event_id->toBe($home->id)->payer_id->toBe($users[1]->id)->amount->toBe(2340)
        ->currency->toBe('EUR')->store->toBe('Lidl')->created_by->toBe($users[1]->id);
    $batch = ImportBatch::sole();
    expect($batch->status)->toBe('imported');

    actingAs($users[1])->post("/m/expense-import/batches/{$batch->id}/undo");
    expect(Spend::count())->toBe(0);
});

it('stops working when the link is revoked or rotated, the switch is off, the module is off, or the person left', function () {
    [$family, $users] = importFamily();
    $url = payUrl($users[1]);
    $ok = fn (?string $to = null) => postJson($to ?? $url, ['amount' => '5', 'merchant' => 'Test'])->status();

    expect($ok())->toBe(200);

    ImportSetting::current()->update(['shortcut_enabled' => false]);
    expect($ok())->toBe(404);
    ImportSetting::current()->update(['shortcut_enabled' => true]);

    app(ModuleRegistry::class)->disable('expense_import', $family->id);
    expect($ok())->toBe(404);
    app(ModuleRegistry::class)->enable('expense_import', $family->id);

    $family->members()->detach($users[1]);
    expect($ok())->toBe(404);
    $family->members()->attach($users[1], ['role' => 'member']);

    $old = $url;
    $new = payUrl($users[1]); // rotate: the old link dies, the new one works
    expect($ok($old))->toBe(404)->and($ok($new))->toBe(200);

    actingAs($users[1])->delete('/m/expense-import/settings/token');
    expect($ok($new))->toBe(404);
});

it('reads any supported currency from the amount, not only the European ones', function () {
    [, $users] = importFamily();
    $url = payUrl($users[1]);
    auth()->logout();

    postJson($url, ['amount' => '1500 JPY', 'merchant' => 'Lawson'])->assertOk();
    postJson($url, ['amount' => '10 HUF', 'merchant' => 'Spar'])->assertOk();

    expect(ImportBatch::sole()->rows()->orderBy('id')->pluck('currency')->all())->toBe(['JPY', 'HUF']);
});

it('refuses a payment without a usable amount', function () {
    [, $users] = importFamily();
    $url = payUrl($users[0]);

    postJson($url, ['merchant' => 'Shop'])->assertStatus(422);
    postJson($url, ['amount' => '10', 'currency' => 'XDR'])->assertStatus(422); // a currency Spending can't hold
    expect(ImportBatch::count())->toBe(0);
});

it('only lets a member make their own link once the Apple Pay link is switched on', function () {
    [, $users] = importFamily(['shortcut_enabled' => false]);
    actingAs($users[1])->post('/m/expense-import/settings/token')->assertNotFound();
    expect(ImportToken::count())->toBe(0);
});
