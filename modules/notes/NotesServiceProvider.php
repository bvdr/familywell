<?php

namespace Modules\Notes;

use App\Models\EntityVisit;
use App\Support\Dashboard\DashboardItems;
use App\Support\Modules\ModuleRegistry;
use App\Support\Sharing\ShareableRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Notifications\NotificationCategories;

class NotesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Opt notes into the core sharing system (addressable as `note` in share URLs).
        ShareableRegistry::register('note', Note::class);

        NotificationCategories::register('notes', 'Notes', 'sticky-note');

        // "Pick up where you left off": notes this user opened in the last few days, tagged with when
        // so the dashboard can order them across modules. Respects visibility. Notes have no agenda
        // (nothing due), so there is no DashboardAgenda provider.
        DashboardItems::register('notes', function () {
            $userId = auth()->id();
            $familyId = CurrentFamily::id();
            if (! $userId || ! $familyId || ! app(ModuleRegistry::class)->isEnabled('notes', $familyId)) {
                return [];
            }

            $visits = EntityVisit::recentMap('note');
            if ($visits->isEmpty()) {
                return [];
            }

            return Note::query()
                ->with(['shareSetting', 'shares', 'folder'])
                ->whereIn('id', $visits->keys())
                ->get()
                ->filter(fn (Note $n) => $n->visibility() === 'family' || $n->shares->contains('user_id', $userId))
                ->map(fn (Note $n) => [
                    'title' => $n->title !== '' ? $n->title : 'Untitled note',
                    'subtitle' => $n->folder?->name ?? 'Notes',
                    'href' => "/m/notes/{$n->id}",
                    'category' => 'notes',
                    'icon' => 'sticky-note',
                    'visitedAt' => $visits[$n->id]->toIso8601String(),
                ])->values()->all();
        });

        // Full Inertia pages, gated by `module:notes` so they are only reachable when the family
        // has enabled the module.
        Route::middleware(['web', 'auth', 'family', 'module:notes'])
            ->prefix('m/notes')
            ->name('notes.')
            ->group(function () {
                Route::get('/', [NotesController::class, 'index'])->name('index');

                Route::post('/folders', [NotesController::class, 'storeFolder'])->name('folders.store');
                Route::patch('/folders/{folder}', [NotesController::class, 'updateFolder'])->name('folders.update');
                Route::delete('/folders/{folder}', [NotesController::class, 'destroyFolder'])->name('folders.destroy');

                Route::post('/', [NotesController::class, 'storeNote'])->name('store');
                Route::get('/{note}', [NotesController::class, 'show'])->name('show');
                Route::patch('/{note}', [NotesController::class, 'updateNote'])->name('update');
                Route::delete('/{note}', [NotesController::class, 'destroyNote'])->name('destroy');
            });
    }
}
