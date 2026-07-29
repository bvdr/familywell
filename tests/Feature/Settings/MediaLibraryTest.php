<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Projects\Project;

use function Pest\Laravel\actingAs;

function mediaFamily(): array
{
    $users = User::factory()->count(2)->create();
    $family = Family::factory()->create(['owner_id' => $users[0]->id]);
    foreach ($users as $i => $u) {
        $family->members()->attach($u, ['role' => $i === 0 ? 'admin' : 'member']);
    }
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);
    app(ModuleRegistry::class)->enable('projects', $family->id);

    return [$family, $users];
}

it('lists a project cover and deletes it', function () {
    Storage::fake('r2');
    [, $users] = mediaFamily();
    actingAs($users[0])->post('/m/projects', ['name' => 'Reno']);
    $project = Project::withoutGlobalScopes()->first();
    actingAs($users[0])->post("/m/projects/{$project->id}/cover", [
        'photo' => UploadedFile::fake()->create('c.jpg', 100, 'image/jpeg'),
        'photo_thumb' => UploadedFile::fake()->create('c.thumb.jpg', 10, 'image/jpeg'),
    ]);
    $path = $project->fresh()->cover_path;

    actingAs($users[0])->get('/settings/media')
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('settings/media')
            ->has('items', 1)
            ->where('items.0.type', 'cover')
            ->where('items.0.name', 'Reno'));

    actingAs($users[0])->delete("/settings/media/cover/{$project->id}")->assertRedirect();
    expect($project->fresh()->cover_path)->toBeNull();
    Storage::disk('r2')->assertMissing($path);
    Storage::disk('r2')->assertMissing($path.'.thumb.jpg');
});

it('hides the cover of a private project from a member who was not shared', function () {
    Storage::fake('r2');
    [, $users] = mediaFamily();
    [$alice, $bob] = $users;
    actingAs($alice)->post('/m/projects', ['name' => 'Secret']);
    $project = Project::withoutGlobalScopes()->first();
    actingAs($alice)->post("/m/projects/{$project->id}/cover", ['photo' => UploadedFile::fake()->create('c.jpg', 100, 'image/jpeg')]);
    actingAs($alice)->patch("/m/projects/{$project->id}", ['visibility' => 'private']);

    actingAs($bob)->get('/settings/media')->assertInertia(fn (Assert $p) => $p->has('items', 0));
});
