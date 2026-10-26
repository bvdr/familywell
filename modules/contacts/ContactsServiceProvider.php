<?php

namespace Modules\Contacts;

use App\Support\Dashboard\DashboardSummaries;
use App\Support\Engagement;
use App\Support\Mcp\McpTools;
use App\Support\Trash\Trash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ContactsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // "Your modules" tile: the family address book's size.
        DashboardSummaries::register('contacts', fn () => ['key' => 'dashboard.sum_contacts', 'params' => ['count' => Person::count()]]);
        // Adding a Person counts as using this module (paywall timing + the admin's progress bar).
        Engagement::track('contacts', Person::class);

        // Full Inertia pages, gated by `module:contacts` so they only exist for families that enabled it.
        // The controller fires the person.* hooks, so `person` is a first-class extensible primitive.
        Route::middleware(['web', 'auth', 'family', 'module:contacts'])
            ->prefix('m/contacts')
            ->name('contacts.')
            ->group(function () {
                Route::get('/', [ContactsController::class, 'index'])->name('index');
                Route::post('/people', [ContactsController::class, 'store'])->name('people.store');
                Route::patch('/people/{person}', [ContactsController::class, 'update'])->name('people.update');
                Route::delete('/people/{person}', [ContactsController::class, 'destroy'])->name('people.destroy');
            });

        // MCP: address-book tools for an AI assistant, gated by the `contacts:read` / `contacts:write`
        // scopes (see docs/mcp.md).
        // Deletes go to the 30-day trash (*_list_deleted / *_restore).
        McpTools::register('contacts', 'Contacts', [
            Mcp\ListContacts::class, Mcp\GetContact::class, Mcp\CreateContact::class, Mcp\UpdateContact::class, Mcp\DeleteContact::class,
            Mcp\ListDeleted::class, Mcp\Restore::class, ]);

        // 30-day trash: deleted contacts in Settings > Recently deleted (ContactsTrash).
        Trash::source('contacts', ContactsTrash::class);
        Trash::register(Person::class);
    }
}
