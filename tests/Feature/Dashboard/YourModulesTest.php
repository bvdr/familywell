<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Modules\Shopping\ShoppingList;

use function Pest\Laravel\actingAs;

// The dashboard's "Your modules" grid: one live line per ENABLED module (DashboardSummaries).

it('sends a live line for each enabled module and none for the others', function () {
    test()->artisan('modules:migrate')->assertExitCode(0);
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    $registry = app(ModuleRegistry::class);
    foreach (['shopping', 'tasks', 'calendar'] as $m) {
        $registry->enable($m, $family->id);
    }

    actingAs($user)->withSession(['active_family_id' => $family->id]);
    App\Support\Tenancy\CurrentFamily::set($family->id);
    $list = ShoppingList::createFor($user, ['name' => 'Groceries']);
    $list->items()->createMany(collect([['name' => 'Milk'], ['name' => 'Eggs'], ['name' => 'Bread', 'bought' => true]])->map(fn ($i) => $i + ['created_by' => $user->id])->all());

    actingAs($user)->withSession(['active_family_id' => $family->id])->get('/dashboard')->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('summaries.shopping', ['key' => 'dashboard.sum_shopping', 'params' => ['count' => 2]])
            ->where('summaries.tasks.key', 'dashboard.sum_tasks')
            ->where('summaries.calendar.key', 'dashboard.sum_calendar')
            ->missing('summaries.spending'));
});
