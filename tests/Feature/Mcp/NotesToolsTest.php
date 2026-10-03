<?php

use App\Support\Tenancy\CurrentFamily;
use Modules\Notes\Folder;
use Modules\Notes\Note;

function notesToken(int $members = 2): array
{
    [$family, $users] = mcpFamily(['notes'], $members);
    $token = mcpConnect($users[0], $family->id, ['notes' => 'write'])['access_token'];

    return [$family, $users, $token];
}

it('creates a note in a folder made on the fly, finds it, and updates it', function () {
    [, , $token] = notesToken();

    [$err, $n] = mcpTool($token, 'notes_create', ['title' => 'Pancakes', 'body' => "# Pancakes\n- 2 eggs\n- flour", 'folder' => 'Recipes']);
    expect($err)->toBeFalse()->and($n['folder'])->toBe('Recipes')->and($n['body'])->toContain('2 eggs');
    // Same folder, different case: reused, not duplicated.
    [, $m] = mcpTool($token, 'notes_create', ['title' => 'Soup', 'folder' => 'recipes']);
    expect(Folder::withoutGlobalScopes()->count())->toBe(1)->and($m['folder'])->toBe('Recipes');

    [, $list] = mcpTool($token, 'notes_list', ['folder' => 'RECIPES']);
    expect(collect($list['notes'])->pluck('title')->sort()->values()->all())->toBe(['Pancakes', 'Soup'])
        ->and($list['notes'][0])->toHaveKey('snippet')->and($list['folders'])->toBe(['Recipes']);

    [, $found] = mcpTool($token, 'notes_search', ['query' => 'EGGS']);
    expect(collect($found['notes'])->pluck('id')->all())->toBe([$n['id']]);

    [, $u] = mcpTool($token, 'notes_update', ['note_id' => $n['id'], 'pinned' => true, 'folder' => null]);
    expect($u['pinned'])->toBeTrue()->and($u['folder'])->toBeNull()->and($u['title'])->toBe('Pancakes');

    [, $got] = mcpTool($token, 'notes_get', ['note_id' => $n['id']]);
    expect($got['body'])->toBe("# Pancakes\n- 2 eggs\n- flour");
});

it('deletes to the trash, lists the trash, and restores', function () {
    [, , $token] = notesToken();
    [, $n] = mcpTool($token, 'notes_create', ['title' => 'Wifi password']);

    [, $d] = mcpTool($token, 'notes_delete', ['note_id' => $n['id']]);
    expect($d['deleted'])->toBeTrue();
    [, $trash] = mcpTool($token, 'notes_list_deleted');
    expect(collect($trash['deleted'])->pluck('id')->all())->toBe([$n['id']]);

    [, $r] = mcpTool($token, 'notes_restore', ['note_id' => $n['id']]);
    expect($r['restored'])->toBeTrue()->and(mcpTool($token, 'notes_list')[1]['notes'])->toHaveCount(1);
});

it('cannot see or touch another member\'s private note, nor delete one it did not create', function () {
    [$family, $users, $token] = notesToken();
    CurrentFamily::set($family->id);
    $private = Note::createFor($users[1], ['title' => 'Diary', 'body' => 'secret']);
    $private->setVisibility('private');
    $shared = Note::createFor($users[1], ['title' => 'Shared']);

    [, $list] = mcpTool($token, 'notes_list');
    expect(collect($list['notes'])->pluck('title')->all())->toBe(['Shared']);
    expect(mcpTool($token, 'notes_search', ['query' => 'secret'])[1]['notes'])->toBe([])
        ->and(mcpTool($token, 'notes_get', ['note_id' => $private->id])[0])->toBeTrue()
        ->and(mcpTool($token, 'notes_update', ['note_id' => $private->id, 'title' => 'x'])[0])->toBeTrue()
        ->and(mcpTool($token, 'notes_delete', ['note_id' => $shared->id])[1])->toContain('created this note');
    expect($private->fresh()->title)->toBe('Diary');
});
