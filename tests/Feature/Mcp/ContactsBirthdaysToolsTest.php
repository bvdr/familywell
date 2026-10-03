<?php

use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Carbon;
use Modules\Birthdays\BirthdayDate;
use Modules\Contacts\Person;

// Contacts + Birthdays over MCP: the same rules as the web forms (shared via Person::rules /
// BirthdayDate::addFor). Both are family-wide, so the family scope is the only access rule.

function peopleToken(array $access = ['contacts' => 'write', 'birthdays' => 'write']): array
{
    [$family, $users] = mcpFamily(['contacts', 'birthdays']);
    CurrentFamily::set($family->id);
    $ana = Person::create(['name' => 'Ana Pop']);
    $ana->syncContactMethods([['type' => 'phone', 'label' => 'mobile', 'value' => '+40 722 000 111']]);
    $token = mcpConnect($users[0], $family->id, $access)['access_token'];

    return [$family, $users, $token, $ana];
}

it('lists, searches and gets contacts, with the birthday extension on get', function () {
    Carbon::setTestNow('2026-09-02');
    [, , $token, $ana] = peopleToken(['contacts' => 'read', 'birthdays' => 'read']);
    Person::create(['name' => 'Mihai']);
    BirthdayDate::create(['person_id' => $ana->id, 'month' => 9, 'day' => 5, 'birthdate' => '1990-09-05']);

    [, $list] = mcpTool($token, 'contacts_list');
    expect(collect($list['people'])->pluck('name')->all())->toBe(['Ana Pop', 'Mihai']);

    [, $found] = mcpTool($token, 'contacts_list', ['query' => '722 000']);
    expect($found['people'])->toHaveCount(1)
        ->and($found['people'][0]['contact_methods'][0])->toBe(['type' => 'phone', 'label' => 'mobile', 'value' => '+40 722 000 111']);

    [, $got] = mcpTool($token, 'contacts_get', ['person_id' => $ana->id]);
    expect($got)->toMatchArray(['id' => $ana->id, 'name' => 'Ana Pop', 'birthMonth' => 9, 'age' => 35])
        ->and($got['url'])->toEndWith('/m/contacts');

    // Read-only token: no write tools.
    expect(mcpToolNames($token))->toContain('contacts_get', 'birthdays_list')
        ->not->toContain('contacts_create')->not->toContain('birthdays_add');
});

it('creates and updates a contact with the web form rules', function () {
    [, , $token, $ana] = peopleToken();

    [, $c] = mcpTool($token, 'contacts_create', ['name' => 'Ioana', 'contact_methods' => [['type' => 'email', 'value' => 'ioana@example.com']]]);
    expect($c['name'])->toBe('Ioana')->and($c['contact_methods'][0]['value'])->toBe('ioana@example.com');

    // Invalid type is refused, like the web form.
    [$err] = mcpTool($token, 'contacts_create', ['name' => 'X', 'contact_methods' => [['type' => 'fax', 'value' => '1']]]);
    expect($err)->toBeTrue();

    // Rename only: contact methods untouched.
    mcpTool($token, 'contacts_update', ['person_id' => $ana->id, 'name' => 'Ana Popescu']);
    expect($ana->fresh()->name)->toBe('Ana Popescu')->and($ana->contactMethods()->count())->toBe(1);

    // contact_methods replaces the whole set.
    [, $u] = mcpTool($token, 'contacts_update', ['person_id' => $ana->id, 'contact_methods' => [['type' => 'email', 'value' => 'ana@example.com']]]);
    expect($u['contact_methods'])->toBe([['type' => 'email', 'label' => null, 'value' => 'ana@example.com']]);
});

it('adds, lists upcoming and updates birthdays', function () {
    Carbon::setTestNow('2026-09-02');
    [, , $token, $ana] = peopleToken();

    // Exact-name match attaches to the existing contact instead of duplicating.
    [, $a] = mcpTool($token, 'birthdays_add', ['name' => 'ana pop', 'birth_month' => 9, 'birth_day' => 5, 'birth_year' => 1990]);
    expect($a)->toMatchArray(['person_id' => $ana->id, 'next_birthday' => '2026-09-05', 'days_until' => 3, 'turning' => 36])
        ->and(Person::count())->toBe(1);

    [, $b] = mcpTool($token, 'birthdays_add', ['name' => 'Radu', 'birth_month' => 12, 'birth_day' => 1]);
    expect($b['year'])->toBeNull()->and(Person::where('name', 'Radu')->exists())->toBeTrue();

    [, $soon] = mcpTool($token, 'birthdays_list', ['within_days' => 30]);
    expect(collect($soon['birthdays'])->pluck('name')->all())->toBe(['Ana Pop']);
    [, $all] = mcpTool($token, 'birthdays_list');
    expect($all['birthdays'])->toHaveCount(2);

    // Day clamps to the month, year dropped when omitted (same as the web form).
    [, $u] = mcpTool($token, 'birthdays_update', ['birthday_id' => $a['id'], 'birth_month' => 2, 'birth_day' => 30]);
    expect($u)->toMatchArray(['month' => 2, 'day' => 29, 'year' => null]);
});

it('never reaches another family\'s contacts or birthdays', function () {
    [, , $token] = peopleToken();

    [$other] = mcpFamily(['contacts', 'birthdays']);
    CurrentFamily::set($other->id);
    $theirs = Person::create(['name' => 'Stranger']);
    $bd = BirthdayDate::create(['person_id' => $theirs->id, 'month' => 1, 'day' => 1]);

    expect(mcpTool($token, 'contacts_get', ['person_id' => $theirs->id])[0])->toBeTrue()
        ->and(mcpTool($token, 'contacts_update', ['person_id' => $theirs->id, 'name' => 'Hacked'])[0])->toBeTrue()
        ->and(mcpTool($token, 'birthdays_update', ['birthday_id' => $bd->id, 'birth_month' => 5, 'birth_day' => 5])[0])->toBeTrue();
    [, $list] = mcpTool($token, 'contacts_list', ['query' => 'Stranger']);
    expect($list['people'])->toBe([]);

    expect($theirs->fresh()->name)->toBe('Stranger')->and($bd->fresh()->month)->toBe(1);
});
