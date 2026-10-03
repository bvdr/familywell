<?php

namespace Modules\Birthdays;

use App\Support\Dashboard\DashboardWidgets;
use App\Support\Mcp\McpTools;
use App\Support\Modules\Hooks;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Birthdays\Console\NotifyBirthdays;
use Modules\Contacts\Person;
use Modules\Notifications\NotificationCategories;

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
    public function register(): void
    {
        $this->commands([NotifyBirthdays::class]);
    }

    public function boot(Hooks $hooks): void
    {
        // The birthday wish has its own on/off row on Settings > Notifications.
        NotificationCategories::register('birthdays', 'Birthdays', 'cake');

        // Morning push for today's birthdays. 06:00 UTC = 08:00/09:00 in Romania (the app runs on UTC and
        // has no per-family timezone yet); idempotent per day, so a re-run is harmless.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('birthdays:notify')->dailyAt('06:00')->withoutOverlapping();
        });

        // Dashboard headline card(s): whoever celebrates today, above Today. null = nobody / module off.
        DashboardWidgets::register('birthdays', function () {
            if (! $this->enabledHere()) {
                return null;
            }
            $today = BirthdayDate::with('person')->on()->get()->filter(fn (BirthdayDate $b) => $b->person);

            return $today->isEmpty() ? null : $today->map(fn (BirthdayDate $b) => [
                'personId' => $b->person_id,
                'name' => $b->person->name,
                'turning' => $b->turning(),
            ])->values()->all();
        });

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

        // MCP: birthday tools for an AI assistant, gated by the `birthdays:read` / `birthdays:write` scopes
        // (see docs/mcp.md). No delete tool: birthdays are not soft-deleted (no trash), so removing one
        // stays a deliberate in-app action.
        McpTools::register('birthdays', 'Birthdays', [
            Mcp\ListBirthdays::class, Mcp\AddBirthday::class, Mcp\UpdateBirthday::class,
        ]);

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
