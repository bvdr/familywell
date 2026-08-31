<?php

namespace Modules\Birthdays;

use App\Support\Modules\Hooks;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Contacts\Person;

/**
 * Extends the Contacts `person` primitive with a birthday, through the person.* hooks (no
 * contacts-module edits): the date is stored in the module's own table, and age + next birthday are
 * derived on read (so "repeats yearly" needs no scheduler). Also serves the home-widget feed. The
 * frontend (a date field in the person editor, a badge on the row, and the home widget) lives in the
 * prebuilt resources/dist/birthdays.js. Every listener self-gates on isEnabled.
 *
 * Calendar: when a calendar module exists it can read the same nextBirthday()/BirthdayDate data to
 * place a yearly all-day event — deliberately not built here (no calendar module yet).
 */
class BirthdaysServiceProvider extends ServiceProvider
{
    public function boot(Hooks $hooks): void
    {
        Route::middleware(['web', 'auth', 'family', 'module:birthdays'])
            ->prefix('m/birthdays')
            ->name('birthdays.')
            ->group(function () {
                Route::get('/upcoming', [BirthdaysController::class, 'upcoming'])->name('upcoming');
            });

        // DATA — add birthdate, age, next birthday + days-until to every person payload.
        $hooks->listen('person.serialize', function (array $payload, Person $person) {
            if (! $this->enabledHere()) {
                return $payload;
            }
            $bd = BirthdayDate::where('person_id', $person->id)->first();
            $payload['birthdate'] = $bd?->birthdate?->toDateString();
            $payload['age'] = $bd?->age();
            $payload['nextBirthday'] = $bd?->nextBirthday()?->toDateString();
            $payload['daysUntilBirthday'] = $bd?->daysUntil();

            return $payload;
        });

        // BEHAVIOR (save) — persist the birthdate from the request (or clear it when emptied).
        $hooks->listen('person.saved', function (Person $person, Request $request) {
            if (! $this->enabledHere() || ! $request->has('birthdate')) {
                return;
            }
            $val = $request->input('birthdate');
            if (empty($val)) {
                BirthdayDate::where('person_id', $person->id)->delete();

                return;
            }
            BirthdayDate::updateOrCreate(['person_id' => $person->id], ['birthdate' => $val]);
        });
    }

    private function enabledHere(): bool
    {
        $familyId = CurrentFamily::id();

        return $familyId && app(ModuleRegistry::class)->isEnabled('birthdays', (int) $familyId);
    }
}
