<?php

namespace Modules\Contacts;

use App\Support\Mcp\McpTools;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ContactsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
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
        // scopes (see docs/mcp.md). No delete tool: people are not soft-deleted (no trash) and deleting
        // one cascades to its birthday and other extension rows, so that stays a deliberate in-app action.
        McpTools::register('contacts', 'Contacts', [
            Mcp\ListContacts::class, Mcp\GetContact::class, Mcp\CreateContact::class, Mcp\UpdateContact::class,
        ]);
    }
}
