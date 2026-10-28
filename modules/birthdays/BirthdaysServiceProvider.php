<?php

namespace Modules\Birthdays;

use App\Support\Calendar\CalendarSources;
use App\Support\Dashboard\DashboardSummaries;
use App\Support\Dashboard\DashboardWidgets;
use App\Support\Engagement;
use App\Support\Mcp\McpTools;
use App\Support\Modules\Hooks;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Translations;
use App\Support\Trash\Trash;
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
        // "Your modules" tile: birthdays in the next 30 days.
        DashboardSummaries::register('birthdays', fn () => ['key' => 'dashboard.sum_birthdays', 'params' => [
            'count' => BirthdayDate::with('person')->get()->filter(fn (BirthdayDate $b) => $b->person && $b->daysUntil() <= 30)->count(),
        ]]);
        // Adding a BirthdayDate counts as using this module (paywall timing + the admin's progress bar).
        Engagement::track('birthdays', BirthdayDate::class);

        // The birthday wish has its own on/off row on Settings > Notifications.
        NotificationCategories::register('birthdays', 'Birthdays', 'cake');

        // Morning push for today's birthdays. 06:00 UTC = 08:00/09:00 in Romania (the app runs on UTC and
        // has no per-family timezone yet); idempotent per day, so a re-run is harmless.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('birthdays:notify')->dailyAt('06:00')->withoutOverlapping();
        });

        // Home's birthday card: the next 14 days. Today's people get the big card (with Call / Send a wish when
        // the contact has a phone), the rest are listed below it. null = nobody in the window / module off.
        DashboardWidgets::register('birthdays', function () {
            if (! $this->enabledHere()) {
                return null;
            }
            $soon = BirthdayDate::soonestFirst()->filter(fn (BirthdayDate $b) => $b->daysUntil() <= 14);
            if ($soon->isEmpty()) {
                return null;
            }
            $soon->load('person.contactMethods');

            return $soon->map(fn (BirthdayDate $b) => [
                'personId' => $b->person_id,
                'name' => $b->person->name,
                'turning' => $b->turning(),
                'date' => $b->nextBirthday()->toDateString(),
                'inDays' => $b->daysUntil(),
                'born' => $b->birthdate?->toDateString(),
                'phone' => $b->person->contactMethods->firstWhere('type', 'phone')?->value,
            ])->values()->all();
        });

        // Calendar: every year on month+day (Feb 29 only in leap years, like the yearly repeat rule). Title says
        // the age they turn when the birth year is known. Server strings via Translations (no :param support,
        // hence strtr).
        CalendarSources::register('birthdays', function (string $from, string $to) {
            if (! $this->enabledHere()) {
                return [];
            }
            $items = [];
            $fromY = (int) substr($from, 0, 4);
            $toY = (int) substr($to, 0, 4);
            foreach (BirthdayDate::with('person')->get() as $b) {
                if (! $b->person) {
                    continue;
                }
                for ($y = $fromY; $y <= $toY; $y++) {
                    if (! checkdate($b->month, $b->day, $y)) {
                        continue;
                    }
                    $date = sprintf('%04d-%02d-%02d', $y, $b->month, $b->day);
                    if ($date < $from || $date > $to) {
                        continue;
                    }
                    $year = $b->year();
                    $title = $year
                        ? strtr(Translations::get('calendar.birthday_turns'), [':name' => $b->person->name, ':age' => $y - $year])
                        : strtr(Translations::get('calendar.birthday'), [':name' => $b->person->name]);
                    $items[] = CalendarSources::item('birthday', $b->id, $title, $date, href: '/m/birthdays', icon: 'cake',
                        people: [['type' => 'person', 'id' => $b->person->id, 'name' => $b->person->name]]);
                }
            }

            return $items;
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
        // (see docs/mcp.md).
        // Deletes go to the 30-day trash (*_list_deleted / *_restore).
        McpTools::register('birthdays', 'Birthdays', [
            Mcp\ListBirthdays::class, Mcp\AddBirthday::class, Mcp\UpdateBirthday::class, Mcp\DeleteBirthday::class,
            Mcp\ListDeleted::class, Mcp\Restore::class, ]);

        // 30-day trash: removed birthdays in Settings > Recently deleted (BirthdaysTrash).
        Trash::source('birthdays', BirthdaysTrash::class);
        Trash::register(BirthdayDate::class);

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
            BirthdayDate::setFor($person->id, $attrs);
        });
    }

    private function enabledHere(): bool
    {
        $familyId = CurrentFamily::id();

        return $familyId && app(ModuleRegistry::class)->isEnabled('birthdays', (int) $familyId);
    }
}
