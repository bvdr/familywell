<?php

use App\Models\User;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Facades\Http;
use Modules\Projects\Project;
use Modules\Spending\Spend;
use Modules\Spending\SpendEvent;

function spendingToken(int $members = 2): array
{
    [$family, $users] = mcpFamily(['spending'], $members);
    $token = mcpConnect($users[0], $family->id, ['spending' => 'write'])['access_token'];

    return [$family, $users, $token];
}

it('adds an expense to the Others list by default, warns on a duplicate, and reads it back', function () {
    [, $users, $token] = spendingToken();

    [$err, $e] = mcpTool($token, 'spending_add_expense', ['amount' => 12.5, 'currency' => 'EUR', 'title' => 'Groceries', 'shop' => 'Lidl', 'date' => '2026-09-20', 'category' => 'food']);
    expect($err)->toBeFalse()
        ->and($e['amount'])->toBe('12.50')->and($e['shop'])->toBe('Lidl')->and($e['paid_by']['name'])->toBe($users[0]->name)
        ->and($e)->not->toHaveKey('warning');

    [, $again] = mcpTool($token, 'spending_add_expense', ['amount' => 12.5, 'currency' => 'EUR', 'shop' => 'lidl', 'date' => '2026-09-20', 'category' => 'food']);
    expect($again['warning'])->toContain('duplicate');

    [, $one] = mcpTool($token, 'spending_get_expense', ['expense_id' => $e['id']]);
    expect($one['title'])->toBe('Groceries');

    [, $list] = mcpTool($token, 'spending_list_expenses', ['shop' => 'LIDL', 'from' => '2026-09-01', 'to' => '2026-09-30']);
    expect($list['expenses'])->toHaveCount(2);

    [, $cats] = mcpTool($token, 'spending_list_categories');
    expect(collect($cats['categories'])->pluck('key'))->toContain('food', 'other')
        ->and($cats['currencies'])->toContain('EUR');
});

it('rejects an unknown category or a payer outside the list', function () {
    [, , $token] = spendingToken();
    $stranger = User::factory()->create();

    expect(mcpTool($token, 'spending_add_expense', ['amount' => 5, 'category' => 'yachts'])[0])->toBeTrue();
    [$err, $msg] = mcpTool($token, 'spending_add_expense', ['amount' => 5, 'paid_by' => $stranger->id]);
    expect($err)->toBeTrue()->and($msg)->toContain('paid_by');
});

it('updates only the passed fields and re-freezes the conversion', function () {
    Http::fake(['*' => Http::response(['rates' => ['EUR' => 0.2, 'RON' => 1, 'USD' => 0.22, 'GBP' => 0.17]])]);
    [, $users, $token] = spendingToken();
    [, $e] = mcpTool($token, 'spending_add_expense', ['amount' => 10, 'currency' => 'EUR', 'title' => 'Pizza', 'category' => 'food']);

    [, $u] = mcpTool($token, 'spending_update_expense', ['expense_id' => $e['id'], 'amount' => 20, 'paid_by' => $users[1]->id]);
    expect($u['amount'])->toBe('20.00')->and($u['title'])->toBe('Pizza')->and($u['category'])->toBe('food')
        ->and($u['paid_by']['name'])->toBe($users[1]->name)->and($u['amount_in_list_currency'])->toBe('20.00');
});

it('summarises a period by category and shop', function () {
    [, , $token] = spendingToken();
    mcpTool($token, 'spending_add_expense', ['amount' => 10, 'currency' => 'EUR', 'category' => 'food', 'shop' => 'Lidl', 'date' => now()->toDateString()]);
    mcpTool($token, 'spending_add_expense', ['amount' => 5, 'currency' => 'EUR', 'category' => 'transport', 'date' => now()->toDateString()]);
    mcpTool($token, 'spending_add_expense', ['amount' => 99, 'currency' => 'EUR', 'category' => 'food', 'date' => now()->subMonths(2)->toDateString()]);

    [, $s] = mcpTool($token, 'spending_summary');
    expect($s['total'])->toBe('15.00')->and($s['expense_count'])->toBe(2)
        ->and($s['by_category'][0])->toBe(['name' => 'food', 'amount' => '10.00'])
        ->and(collect($s['by_shop'])->pluck('name')->all())->toBe(['Lidl', '(no shop)']);
});

it('deletes to the trash, lists the trash, and restores', function () {
    [, , $token] = spendingToken();
    [, $e] = mcpTool($token, 'spending_add_expense', ['amount' => 3, 'category' => 'food']);

    [, $d] = mcpTool($token, 'spending_delete_expense', ['expense_id' => $e['id']]);
    expect($d['deleted'])->toBeTrue();
    expect(mcpTool($token, 'spending_list_expenses')[1]['expenses'])->toBe([]);

    [, $trash] = mcpTool($token, 'spending_list_deleted');
    expect(collect($trash['deleted'])->pluck('id')->all())->toBe([$e['id']]);

    [, $r] = mcpTool($token, 'spending_restore', ['expense_id' => $e['id']]);
    expect($r['restored'])->toBeTrue()->and(mcpTool($token, 'spending_list_expenses')[1]['expenses'])->toHaveCount(1);
});

it('cannot see or change expenses on another member\'s private list', function () {
    [$family, $users, $token] = spendingToken();
    CurrentFamily::set($family->id);
    $private = SpendEvent::createFor($users[1], ['name' => 'Gifts', 'currency' => 'EUR']);
    $private->setVisibility('private');
    $hidden = $private->spends()->create([...$private->spendColumns(['payer_key' => 'u'.$users[1]->id, 'amount' => 50, 'currency' => 'EUR', 'category' => 'other', 'spent_at' => '2026-09-01']), 'created_by' => $users[1]->id]);

    expect(mcpTool($token, 'spending_list_expenses')[1]['expenses'])->toBe([])
        ->and(mcpTool($token, 'spending_get_expense', ['expense_id' => $hidden->id])[0])->toBeTrue()
        ->and(mcpTool($token, 'spending_update_expense', ['expense_id' => $hidden->id, 'amount' => 1])[0])->toBeTrue()
        ->and(mcpTool($token, 'spending_delete_expense', ['expense_id' => $hidden->id])[0])->toBeTrue()
        ->and(mcpTool($token, 'spending_add_expense', ['amount' => 1, 'list_id' => $private->id])[0])->toBeTrue();
    expect(Spend::withoutGlobalScope('family')->find($hidden->id)->amount)->toBe(5000);
});

it('creates lists (project ones kept out of monthly) and sets the per-expense monthly flag', function () {
    [$family, $users] = mcpFamily(['spending', 'projects'], 2);
    $token = mcpConnect($users[0], $family->id, ['spending' => 'write', 'projects' => 'write'])['access_token'];
    CurrentFamily::set($family->id);
    $project = Project::createFor($users[0], ['name' => 'Kitchen']);

    [$err, $plain] = mcpTool($token, 'spending_create_list', ['name' => 'Groceries', 'currency' => 'EUR']);
    expect($err)->toBeFalse()->and($plain['count_in_monthly'])->toBeTrue()->and($plain['project_id'])->toBeNull();

    [$err, $kitchen] = mcpTool($token, 'spending_create_list', ['name' => 'Kitchen costs', 'currency' => 'EUR', 'project_id' => $project->id]);
    expect($err)->toBeFalse()->and($kitchen['count_in_monthly'])->toBeFalse()
        ->and($project->links()->where(['linkable_type' => 'spend_event', 'linkable_id' => $kitchen['id']])->exists())->toBeTrue();

    $today = now()->toDateString();
    [, $tiles] = mcpTool($token, 'spending_add_expense', ['amount' => 100, 'list_id' => $kitchen['id'], 'category' => 'other', 'date' => $today]);
    [, $paint] = mcpTool($token, 'spending_add_expense', ['amount' => 5, 'list_id' => $kitchen['id'], 'category' => 'other', 'date' => $today, 'count_in_monthly' => true]);
    mcpTool($token, 'spending_add_expense', ['amount' => 10, 'list_id' => $plain['id'], 'category' => 'other', 'date' => $today]);
    expect($tiles['count_in_monthly'])->toBeFalse()->and($paint['count_in_monthly'])->toBeTrue();

    [, $sum] = mcpTool($token, 'spending_summary');
    expect($sum['total'])->toBe('15.00')->and($sum['expense_count'])->toBe(2);

    // null puts the expense back on the list's setting.
    [, $back] = mcpTool($token, 'spending_update_expense', ['expense_id' => $paint['id'], 'count_in_monthly' => null]);
    expect($back['count_in_monthly'])->toBeFalse();

    // Linking to a project needs projects access on the token.
    $narrow = mcpConnect($users[0], $family->id, ['spending' => 'write'])['access_token'];
    [$err, $msg] = mcpTool($narrow, 'spending_create_list', ['name' => 'Nope', 'project_id' => $project->id]);
    expect($err)->toBeTrue()->and($msg)->toContain('no write access to projects');
});

it('moves an expense to another list, which it then follows for monthly spending', function () {
    [, $users, $token] = spendingToken();
    [, $home] = mcpTool($token, 'spending_create_list', ['name' => 'Corbeanca', 'currency' => 'RON']);
    [, $box] = mcpTool($token, 'spending_create_list', ['name' => 'Container', 'currency' => 'RON', 'count_in_monthly' => false]);
    [, $e] = mcpTool($token, 'spending_add_expense', ['amount' => 1500, 'currency' => 'RON', 'title' => 'Transport container', 'category' => 'transport', 'date' => '2026-10-11', 'list_id' => $home['id']]);
    expect($e['count_in_monthly'])->toBeTrue();

    [$err, $moved] = mcpTool($token, 'spending_move_expense', ['expense_id' => $e['id'], 'list_id' => $box['id']]);
    expect($err)->toBeFalse()
        ->and($moved['list']['id'])->toBe($box['id'])
        ->and($moved['amount'])->toBe('1500.00')->and($moved['date'])->toBe('2026-10-11')
        ->and($moved['category'])->toBe('transport')->and($moved['paid_by']['name'])->toBe($users[0]->name)
        ->and($moved['count_in_monthly'])->toBeFalse(); // no own override: follows the new list

    [$err, $msg] = mcpTool($token, 'spending_move_expense', ['expense_id' => $e['id'], 'list_id' => $box['id']]);
    expect($err)->toBeTrue()->and($msg)->toBe("That's the same list.");
    expect(mcpTool($token, 'spending_move_expense', ['expense_id' => $e['id'], 'list_id' => 999999])[0])->toBeTrue();
});
