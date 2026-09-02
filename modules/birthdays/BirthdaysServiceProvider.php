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
 * contacts-module edits): month + day (year optional) live in the module's own table, and age +
 * next birthday are derived on read (so "repeats yearly" needs no scheduler). Also owns the
 * /m/birthdays page (list + quick-add, which creates the person through the primitive so it shows
 * up in Contacts) and serves the home-widget feed. The slot UI (day/month field in the person
 * editor, a badge on the row, the home widget) lives in the prebuilt resources/dist/birthdays.js;
 * the page is an Inertia page under resources/js/pages/birthdays. Every listener self-gates on
 * isEnabled.
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
                Route::get('/', [BirthdaysController::class, 'index'])->name('index');
                Route::post('/', [BirthdaysController::class, 'store'])->name('store');
                Route::patch('/{birthday}', [BirthdaysController::class, 'update'])->name('update');
                Route::delete('/{birthday}', [BirthdaysController::class, 'destroy'])->name('destroy');
                Route::get('/upcoming', [BirthdaysController::class, 'upcoming'])->name('upcoming');
            });

        // DATA — add birthdate, age, next birthday + days-until to every person payload.
        $hooks->listen('person.serialize', function (array $payload, Person $person) {
            if (! $this->enabledHere()) {
                return $payload;
            }
            $bd = BirthdayDate::where('person_id', $person->id)->first();
            $payload['birthdate'] = $bd?->birthdate?->toDateString();
            $payload['birthMonth'] = $bd?->month;
            $payload['birthDay'] = $bd?->day;
            $payload['birthYear'] = $bd?->year();
            $payload['age'] = $bd?->age();
            $payload['nextBirthday'] = $bd?->nextBirthday()?->toDateString();
            $payload['daysUntilBirthday'] = $bd?->daysUntil();

            return $payload;
        });

        // BEHAVIOR (save) — persist the birthday from the request (or clear it when emptied).
        // Accepts month/day (+ optional year) from the editor field, or a legacy full `birthdate`.
        $hooks->listen('person.saved', function (Person $person, Request $request) {
            if (! $this->enabledHere() || ! $request->hasAny(['birth_month', 'birthdate'])) {
                return;
            }
            $attrs = BirthdaysController::attributesFromRequest($request);
            if (! $attrs) {
                BirthdayDate::where('person_id', $person->id)->delete();

                return;
            }
            BirthdayDate::updateOrCreate(['person_id' => $person->id], $attrs);
        });
    }

    private function enabledHere(): bool
    {
        $familyId = CurrentFamily::id();

        return $familyId && app(ModuleRegistry::class)->isEnabled('birthdays', (int) $familyId);
    }
}
