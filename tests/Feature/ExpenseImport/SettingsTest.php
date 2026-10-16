<?php

use App\Support\Modules\ModuleRegistry;
use Modules\ExpenseImport\ImportSetting;
use Modules\Spending\SpendEvent;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/helpers.php';

it('shows the settings to every member, with their own link', function () {
    [, $users] = importFamily();
    actingAs($users[1])->get('/m/expense-import/settings')->assertInertia(fn ($p) => $p
        ->component('expense_import/settings')
        ->where('canManage', false)
        ->where('settings.statementsEnabled', true)
        ->where('payUrl', null)
        ->has('members', 2)->etc());
});

it('lets only admins change the switches and defaults, and checks the defaults are real', function () {
    [, $users] = importFamily(['statements_enabled' => false]);
    $home = SpendEvent::createFor($users[0], ['name' => 'Home', 'currency' => 'EUR']);

    actingAs($users[1])->patch('/m/expense-import/settings', ['statements_enabled' => true])->assertForbidden();
    actingAs($users[0])->patch('/m/expense-import/settings', ['default_event_id' => 999999])->assertSessionHasErrors('default_event_id');
    actingAs($users[0])->patch('/m/expense-import/settings', ['default_payer_key' => 'u999999'])->assertSessionHasErrors('default_payer_key');

    actingAs($users[0])->patch('/m/expense-import/settings', [
        'statements_enabled' => true, 'auto_save' => true, 'default_event_id' => $home->id, 'default_payer_key' => 'u'.$users[1]->id,
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect(ImportSetting::current())->statements_enabled->toBeTrue()->auto_save->toBeTrue()
        ->default_event_id->toBe($home->id)->default_payer_key->toBe('u'.$users[1]->id);
});

it('is unreachable when the family has the module off', function () {
    [$family, $users] = importFamily();
    app(ModuleRegistry::class)->disable('expense_import', $family->id);
    actingAs($users[0])->get('/m/expense-import')->assertNotFound();
    actingAs($users[0])->get('/m/expense-import/settings')->assertNotFound();
});
