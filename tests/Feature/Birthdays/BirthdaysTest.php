<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Birthdays\BirthdayDate;
use Modules\Birthdays\BirthdaysController;
use Modules\Contacts\Person;

use function Pest\Laravel\actingAs;

function birthdaysFamily(): array
{
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);
    $registry = app(ModuleRegistry::class);
    $registry->enable('contacts', $family->id);
    $registry->enable('birthdays', $family->id);

    return [$family, $user];
}

it('quick-adds a person with day and month only, who becomes a contact', function () {
    [$family, $user] = birthdaysFamily();
    Carbon::setTestNow('2026-09-02');

    actingAs($user)->post('/m/birthdays', ['name' => 'Ana', 'birth_day' => 5, 'birth_month' => 9])->assertRedirect();

    $person = Person::where('name', 'Ana')->firstOrFail();
    $bd = BirthdayDate::where('person_id', $person->id)->firstOrFail();
    expect($bd->month)->toBe(9)->and($bd->day)->toBe(5)->and($bd->birthdate)->toBeNull()->and($bd->age())->toBeNull();
    expect($bd->nextBirthday()->toDateString())->toBe('2026-09-05')->and($bd->daysUntil())->toBe(3);

    // Listed in Contacts with the extension fields, and on the Birthdays page.
    actingAs($user)->get('/m/contacts')->assertOk()->assertInertia(fn ($p) => $p
        ->where('people.0.name', 'Ana')->where('people.0.birthMonth', 9)->where('people.0.birthDay', 5)->where('people.0.birthYear', null));
    actingAs($user)->get('/m/birthdays')->assertOk()->assertInertia(fn ($p) => $p
        ->where('birthdays.0.name', 'Ana')->where('birthdays.0.daysUntil', 3)->where('birthdays.0.turning', null));
});

it('attaches the birthday to an existing contact with the same name instead of duplicating', function () {
    [$family, $user] = birthdaysFamily();
    actingAs($user)->post('/m/contacts/people', ['name' => 'Mihai'])->assertRedirect();

    actingAs($user)->post('/m/birthdays', ['name' => 'mihai', 'birth_day' => 29, 'birth_month' => 2, 'birth_year' => 1992])->assertRedirect();

    expect(Person::count())->toBe(1);
    $bd = BirthdayDate::firstOrFail();
    expect($bd->birthdate->toDateString())->toBe('1992-02-29')->and($bd->year())->toBe(1992);
});

it('stores a year-less birthday from the contact editor and clears it when emptied', function () {
    [$family, $user] = birthdaysFamily();
    actingAs($user)->post('/m/contacts/people', ['name' => 'Ioana', 'birth_day' => 31, 'birth_month' => 4])->assertRedirect();
    $person = Person::firstOrFail();
    // Day clamps to what the month has (Apr 31 -> Apr 30).
    expect(BirthdayDate::where('person_id', $person->id)->value('day'))->toBe(30);

    actingAs($user)->patch("/m/contacts/people/{$person->id}", ['birth_day' => '', 'birth_month' => ''])->assertRedirect();
    expect(BirthdayDate::where('person_id', $person->id)->exists())->toBeFalse();
});

it('edits and removes a birthday, keeping the person', function () {
    [$family, $user] = birthdaysFamily();
    actingAs($user)->post('/m/birthdays', ['name' => 'Dan', 'birth_day' => 1, 'birth_month' => 1]);
    $bd = BirthdayDate::firstOrFail();

    actingAs($user)->patch("/m/birthdays/{$bd->id}", ['birth_day' => 2, 'birth_month' => 3, 'birth_year' => 2000])->assertRedirect();
    expect($bd->fresh()->birthdate->toDateString())->toBe('2000-03-02');

    actingAs($user)->delete("/m/birthdays/{$bd->id}")->assertRedirect();
    expect(BirthdayDate::count())->toBe(0)->and(Person::count())->toBe(1);
});

it('rejects an invalid day or month', function () {
    [$family, $user] = birthdaysFamily();
    actingAs($user)->from('/m/birthdays')->post('/m/birthdays', ['name' => 'X', 'birth_day' => 0, 'birth_month' => 13])
        ->assertSessionHasErrors(['birth_day', 'birth_month']);
});

it('feeds the upcoming widget without a year', function () {
    [$family, $user] = birthdaysFamily();
    Carbon::setTestNow('2026-09-02');
    actingAs($user)->post('/m/birthdays', ['name' => 'Ana', 'birth_day' => 2, 'birth_month' => 9]);

    actingAs($user)->getJson('/m/birthdays/upcoming')->assertOk()
        ->assertJsonPath('birthdays.0.name', 'Ana')->assertJsonPath('birthdays.0.daysUntil', 0)->assertJsonPath('birthdays.0.turning', null);
});

it('caps the upcoming widget feed to the nearest few, soonest first, and reports the total', function () {
    [$family, $user] = birthdaysFamily();
    Carbon::setTestNow('2026-09-02');
    // Eight birthdays, one per day, posted out of order so the cap can't just be "first N inserted".
    foreach ([7, 2, 5, 0, 3, 1, 6, 4] as $offset) {
        actingAs($user)->post('/m/birthdays', ['name' => 'P'.$offset, 'birth_day' => 2 + $offset, 'birth_month' => 9]);
    }

    $json = actingAs($user)->getJson('/m/birthdays/upcoming')->assertOk()
        ->assertJsonCount(BirthdaysController::WIDGET_LIMIT, 'birthdays')
        ->assertJsonPath('total', 8)
        ->json();
    expect(array_column($json['birthdays'], 'daysUntil'))->toBe([0, 1, 2, 3, 4]);

    // The full Birthdays page still lists everyone.
    actingAs($user)->get('/m/birthdays')->assertOk()
        ->assertInertia(fn ($page) => $page->component('birthdays/index')->has('birthdays', 8));
});

it('adds a birthday to an existing contact with their own reminder, and Use default clears it', function () {
    [, $user] = birthdaysFamily();
    actingAs($user)->post('/m/contacts/people', ['name' => 'Mihai Rusu'])->assertRedirect();
    $mihai = Person::firstOrFail();

    actingAs($user)->post('/m/birthdays', ['person_id' => $mihai->id, 'birth_day' => 14, 'birth_month' => 11, 'birth_year' => 1988, 'remind_days' => 5])
        ->assertSessionHasNoErrors();
    expect(Person::count())->toBe(1); // no duplicate contact
    $bd = BirthdayDate::where('person_id', $mihai->id)->firstOrFail();
    expect($bd->remind_days)->toBe(5);

    actingAs($user)->get('/m/birthdays')->assertInertia(fn ($p) => $p
        ->where('birthdays.0.remindDays', 5)->where('defaults.remindDays', 3)->where('defaults.sendHour', 9)
        ->where('people.0.name', 'Mihai Rusu'));

    actingAs($user)->patch("/m/birthdays/{$bd->id}", ['birth_day' => 14, 'birth_month' => 11, 'remind_days' => null])->assertSessionHasNoErrors();
    expect($bd->fresh()->remind_days)->toBeNull();

    actingAs($user)->post('/m/birthdays', ['person_id' => $mihai->id, 'birth_day' => 1, 'birth_month' => 1, 'remind_days' => 61])
        ->assertSessionHasErrors('remind_days');
});

it('loads Contacts with the same number of queries for 2 people or 12 (no birthday N+1)', function () {
    [$family, $user] = birthdaysFamily();
    $count = function () use ($user) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        actingAs($user)->get('/m/contacts')->assertOk();

        return count(DB::getQueryLog());
    };
    $add = fn (int $n) => collect(range(1, $n))->each(fn ($i) => actingAs($user)
        ->post('/m/birthdays', ['name' => "P{$i}-".uniqid(), 'birth_day' => $i, 'birth_month' => 3, 'birth_year' => 1990]));

    $add(2);
    $count(); // the first visit does one-off writes (last seen); measure warm requests on both sides
    $few = $count();
    $add(10);
    $count();

    expect($count())->toBe($few);
});

it('works out age by the calendar: the day before, on, and a Feb 29 birthday in a non-leap year', function () {
    [$family, $user] = birthdaysFamily();
    actingAs($user)->post('/m/birthdays', ['name' => 'Leap', 'birth_day' => 29, 'birth_month' => 2, 'birth_year' => 2000]);
    $bd = BirthdayDate::firstOrFail();

    Carbon::setTestNow('2025-02-28');
    expect($bd->age())->toBe(24)->and($bd->turning())->toBe(25)->and($bd->daysUntil())->toBe(1);
    Carbon::setTestNow('2025-03-01'); // non-leap: celebrated today, so the age has ticked over
    expect($bd->age())->toBe(25)->and($bd->turning())->toBe(25)->and($bd->daysUntil())->toBe(0)
        ->and($bd->nextBirthday()->toDateString())->toBe('2025-03-01');
    Carbon::setTestNow('2028-02-29');
    expect($bd->age())->toBe(28)->and($bd->daysUntil())->toBe(0);
});
