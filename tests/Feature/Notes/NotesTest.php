<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Modules\Notes\Folder;
use Modules\Notes\Note;

use function Pest\Laravel\actingAs;

function notesFamily(int $members = 2): array
{
    $users = User::factory()->count($members)->create();
    $family = Family::factory()->create(['owner_id' => $users[0]->id]);
    foreach ($users as $i => $u) {
        $family->members()->attach($u, ['role' => $i === 0 ? 'admin' : 'member']);
    }
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);
    app(ModuleRegistry::class)->enable('notes', $family->id);

    return [$family, $users];
}

it('404s when the module is not enabled', function () {
    $user = User::factory()->create();
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate');

    actingAs($user)->get('/m/notes')->assertNotFound();
});

it('creates a note with an owner share and opens it', function () {
    [, $users] = notesFamily();

    actingAs($users[0])->post('/m/notes')->assertRedirect();

    $note = Note::withoutGlobalScopes()->first();
    expect($note->created_by)->toBe($users[0]->id)
        ->and($note->visibility())->toBe('family')
        ->and($note->shares()->where('role', 'owner')->exists())->toBeTrue();

    actingAs($users[0])->get("/m/notes/{$note->id}")->assertOk();
    actingAs($users[0])->get('/m/notes')->assertOk();
});

it('auto-saves title, body, pin, color and visibility', function () {
    [, $users] = notesFamily();
    actingAs($users[0])->post('/m/notes');
    $note = Note::withoutGlobalScopes()->first();

    actingAs($users[0])->patch("/m/notes/{$note->id}", [
        'title' => 'Packing list',
        'body' => "# Trip\n- [ ] socks\n- [x] passport",
        'pinned' => true,
        'color' => 'blue',
        'visibility' => 'private',
    ])->assertRedirect();

    $note->refresh();
    expect($note->title)->toBe('Packing list')
        ->and($note->pinned)->toBeTrue()
        ->and($note->color)->toBe('blue')
        ->and($note->visibility())->toBe('private');
});

it('files a note under a folder and detaches it when the folder is deleted', function () {
    [, $users] = notesFamily();
    actingAs($users[0])->post('/m/notes/folders', ['name' => 'Travel'])->assertRedirect();
    $folder = Folder::withoutGlobalScopes()->first();

    actingAs($users[0])->post('/m/notes');
    $note = Note::withoutGlobalScopes()->first();
    actingAs($users[0])->patch("/m/notes/{$note->id}", ['folder_id' => $folder->id])->assertRedirect();
    expect($note->refresh()->folder_id)->toBe($folder->id);

    // Deleting the folder detaches the note (folder_id -> null), it does not delete the note.
    actingAs($users[0])->delete("/m/notes/folders/{$folder->id}")->assertRedirect();
    expect(Note::withoutGlobalScopes()->find($note->id))->not->toBeNull()
        ->and($note->refresh()->folder_id)->toBeNull();
});

it('hides a private note from other family members', function () {
    [, $users] = notesFamily();
    actingAs($users[0])->post('/m/notes');
    $note = Note::withoutGlobalScopes()->first();
    actingAs($users[0])->patch("/m/notes/{$note->id}", ['visibility' => 'private']);

    // The other member cannot view it and it is absent from their index.
    actingAs($users[1])->get("/m/notes/{$note->id}")->assertForbidden();
});

it('only the creator can delete a note', function () {
    [, $users] = notesFamily();
    actingAs($users[0])->post('/m/notes');
    $note = Note::withoutGlobalScopes()->first();

    actingAs($users[1])->delete("/m/notes/{$note->id}")->assertForbidden();
    actingAs($users[0])->delete("/m/notes/{$note->id}")->assertRedirect();
    expect(Note::withoutGlobalScope('family')->find($note->id))->toBeNull();
    $this->assertSoftDeleted($note); // 30-day trash
});
