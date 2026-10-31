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

it('trashes a contact with their birthday hidden, and restores both', function () {
    Carbon::setTestNow('2026-09-02');
    [, , $token, $ana] = peopleToken();
    mcpTool($token, 'birthdays_add', ['name' => 'Ana Pop', 'birth_day' => 3, 'birth_month' => 10]);

    [$err] = mcpTool($token, 'contacts_delete', ['person_id' => $ana->id]);
    expect($err)->toBeFalse()
        ->and(mcpTool($token, 'contacts_list')[1]['contacts'] ?? [])->toBe([])
        ->and(mcpTool($token, 'birthdays_list')[1]['birthdays'])->toBe([]) // hidden with the contact
        ->and(mcpTool($token, 'birthdays_list_trash')[1]['deleted'])->toBe([]); // comes back via the contact
    [, $trash] = mcpTool($token, 'contacts_list_trash');
    expect($trash['deleted'][0])->toMatchArray(['type' => 'contact', 'id' => $ana->id, 'title' => 'Ana Pop']);

    [$err, $r] = mcpTool($token, 'contacts_restore', ['id' => $ana->id]);
    expect($err)->toBeFalse()->and($r['url'])->toEndWith('/m/contacts');
    [, $got] = mcpTool($token, 'contacts_get', ['person_id' => $ana->id]);
    expect($got['contact_methods'][0]['value'])->toBe('+40 722 000 111')
        ->and(mcpTool($token, 'birthdays_list')[1]['birthdays'][0]['name'])->toBe('Ana Pop');
});

it('trashes a birthday, restores it, and lets a new date revive a trashed one', function () {
    [, , $token, $ana] = peopleToken();
    [, $b] = mcpTool($token, 'birthdays_add', ['name' => 'Ana Pop', 'birth_day' => 3, 'birth_month' => 10]);
    mcpTool($token, 'birthdays_delete', ['birthday_id' => $b['id']]);

    [, $trash] = mcpTool($token, 'birthdays_list_trash');
    expect($trash['deleted'][0])->toMatchArray(['type' => 'birthday', 'id' => $b['id'], 'title' => 'Ana Pop']);
    expect(mcpTool($token, 'birthdays_restore', ['id' => $b['id']])[0])->toBeFalse()
        ->and(BirthdayDate::find($b['id'])->day)->toBe(3);

    // Removed again, then a new date is set: one row (person_id is unique), live, with the new date.
    mcpTool($token, 'birthdays_delete', ['birthday_id' => $b['id']]);
    [$err] = mcpTool($token, 'birthdays_add', ['name' => 'Ana Pop', 'birth_day' => 9, 'birth_month' => 11]);
    expect($err)->toBeFalse()->and(BirthdayDate::withTrashed()->where('person_id', $ana->id)->count())->toBe(1)
        ->and(BirthdayDate::where('person_id', $ana->id)->value('day'))->toBe(9);
});

it('erases a trashed contact with their contact methods and birthday when the trash is purged', function () {
    [, , $token, $ana] = peopleToken();
    mcpTool($token, 'birthdays_add', ['name' => 'Ana Pop', 'birth_day' => 3, 'birth_month' => 10]);
    mcpTool($token, 'contacts_delete', ['person_id' => $ana->id]);

    $this->travel(31)->days();
    $this->artisan('trash:purge')->assertExitCode(0);
    $this->assertDatabaseMissing('contacts_people', ['id' => $ana->id]);
    $this->assertDatabaseMissing('contacts_contact_methods', ['person_id' => $ana->id]);
    $this->assertDatabaseMissing('birthdays_dates', ['person_id' => $ana->id]);
});
