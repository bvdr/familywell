<?php

use App\Mcp\Apps\Widget;
use App\Models\User;
use App\Support\Demo\DemoFamily;
use App\Support\Mcp\McpTools;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Database\Seeders\DemoSeeder;
use Illuminate\Http\UploadedFile;
use Modules\Calendar\CalendarEvent;
use Modules\Contacts\Person;
use Modules\Notes\Note;
use Modules\Projects\Project;
use Modules\Recipes\Recipe;
use Modules\Shopping\ShoppingList;
use Modules\Tasks\TaskList;
use Modules\Vehicles\Vehicle;

// Feeds the MCP widget viewport checks (tests/Viewports/mcp-widgets.spec.ts): every widget view is rendered
// from the REAL widget page and the REAL tool result for the seeded demo family, so the guardrail sees what
// Claude/ChatGPT render. CI runs Pest before Playwright; locally run this test first.
// ponytail: fixtures written by a test instead of an artisan command, so no dump-tool ships to production.
it('writes every MCP widget view as a fixture for the viewport checks', function () {
    Storage::fake('r2'); // the seeder uploads demo photos
    test()->artisan('modules:migrate')->assertExitCode(0);
    $this->seed(DemoSeeder::class);
    $user = User::where('email', DemoSeeder::OWNER_EMAIL)->firstOrFail();
    $familyId = DemoFamily::locate()->id;
    CurrentFamily::set($familyId);
    mcpKeys();
    $write = array_fill_keys(array_intersect(array_keys(McpTools::modules()), app(ModuleRegistry::class)->enabledIds($familyId)), 'write');
    $token = mcpConnect($user, $familyId, $write)['access_token'];

    // One recipe with a photo, so the hero state is checked too (the demo recipes have none).
    $photo = database_path('seeders/demo-images/project-kitchen.thumb.jpg');
    $withPhoto = Recipe::where('family_id', $familyId)->orderByDesc('id')->firstOrFail();
    $withPhoto->setPhoto(new UploadedFile($photo, 'photo.jpg', 'image/jpeg', null, true));

    // name => [widget, tool, args]. One entry per distinct view a widget draws.
    $views = [
        'tasks-list' => ['tasks', 'tasks_get_list', ['list_id' => TaskList::where('family_id', $familyId)->value('id')]],
        'tasks-search' => ['tasks', 'tasks_search', []],
        'shopping-list' => ['shopping', 'shopping_get_list', ['list_id' => ShoppingList::where('family_id', $familyId)->value('id')]],
        'project' => ['project', 'projects_get', ['project_id' => Project::where('family_id', $familyId)->value('id')]],
        'today' => ['today', 'get_today', []],
        'spending-summary' => ['spending', 'spending_get_summary', []],
        'spending-expenses' => ['spending', 'spending_list_expenses', []],
        'inventory-list' => ['inventory', 'inventory_list', []],
        'inventory-attention' => ['inventory', 'inventory_list_needs_attention', []],
        'vehicle' => ['vehicle', 'vehicles_get', ['vehicle_id' => Vehicle::where('family_id', $familyId)->value('id')]],
        'vehicles-list' => ['vehicle', 'vehicles_list', []],
        'vehicle-history' => ['vehicle', 'vehicles_get_history', ['vehicle_id' => Vehicle::where('family_id', $familyId)->value('id')]],
        'birthdays' => ['birthdays', 'birthdays_list', []],
        'calendar-list' => ['calendar', 'calendar_list_entries', ['from' => today()->toDateString(), 'to' => today()->addDays(14)->toDateString()]],
        'calendar-event' => ['calendar', 'calendar_get_event', ['id' => CalendarEvent::where('family_id', $familyId)->value('id')]],
        'note' => ['note', 'notes_get', ['note_id' => Note::where('family_id', $familyId)->value('id')]],
        'contact' => ['contact', 'contacts_get', ['person_id' => Person::where('family_id', $familyId)->value('id')]],
        'recipes-list' => ['recipe', 'recipes_list', []],
        'recipe' => ['recipe', 'recipes_get', ['recipe_id' => Recipe::where('family_id', $familyId)->value('id')]],
        'recipe-photo' => ['recipe', 'recipes_get', ['recipe_id' => $withPhoto->id]],
    ];
    expect(array_diff(array_keys(Widget::WIDGETS), array_column($views, 0)))->toBe([]); // a new widget needs a view here

    $dir = base_path('tests/Viewports/mcp-fixtures');
    File::ensureDirectoryExists($dir);
    File::cleanDirectory($dir);
    foreach ($views as $name => [$widget, $tool, $args]) {
        $result = mcpCall($token, 'tools/call', ['name' => $tool, 'arguments' => (object) $args])->assertOk()->json('result');
        expect($result['isError'] ?? false)->toBeFalse("{$tool} failed: ".json_encode($result));
        if (isset($result['structuredContent']['photo_url'])) {
            // The mock host has no server behind the signed photo URL: inline the image so it renders.
            $result['structuredContent']['photo_url'] = 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents($photo));
        }
        $html = mcpCall($token, 'resources/read', ['uri' => Widget::uriFor($widget)])->assertOk()->json('result.contents.0.text');
        File::put("{$dir}/{$name}.json", json_encode(['widget' => $widget, 'args' => (object) $args, 'result' => $result, 'html' => $html]));
    }
});
