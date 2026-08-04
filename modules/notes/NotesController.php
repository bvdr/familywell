<?php

namespace Modules\Notes;

use App\Http\Controllers\Controller;
use App\Models\EntityVisit;
use App\Models\Family;
use App\Support\Sharing\ShareAccess;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotesController extends Controller
{
    public function __construct(private ShareAccess $access) {}

    public function index(Request $request): Response
    {
        $userId = $request->user()->id;

        $notes = Note::query()
            ->with(['shareSetting', 'shares'])
            ->orderByDesc('pinned')
            ->latest('updated_at')
            ->get()
            // BelongsToFamily already limits to the current family; `family`-visibility notes are
            // visible to every member, `invited`/`private` need an explicit share row.
            ->filter(fn (Note $n) => $n->visibility() === 'family' || $n->shares->contains('user_id', $userId))
            ->values();

        // Folders are family-wide; show every one (with how many of the visible notes sit inside it).
        $counts = $notes->groupBy('folder_id')->map->count();
        $folders = Folder::query()->orderBy('position')->latest('id')->get()
            ->map(fn (Folder $f) => [
                'id' => $f->id,
                'name' => $f->name,
                'color' => $f->color,
                'count' => (int) ($counts[$f->id] ?? 0),
            ])->values();

        return Inertia::render('notes/index', [
            'notes' => $notes->map(fn (Note $n) => $this->cardPayload($n))->values(),
            'folders' => $folders,
        ]);
    }

    // Compact shape for the index grid: enough to render a card + filter/search client-side.
    private function cardPayload(Note $n): array
    {
        return [
            'id' => $n->id,
            'title' => $n->title,
            'body' => $n->body, // full body; the client makes a plain-text snippet + searches it
            'color' => $n->color,
            'pinned' => $n->pinned,
            'folderId' => $n->folder_id,
            'visibility' => $n->visibility(),
            'updatedAt' => $n->updated_at->toIso8601String(),
        ];
    }

    public function storeNote(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'folder_id' => ['nullable', 'integer', 'exists:notes_folders,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:20'],
        ]);

        $note = Note::create([
            'folder_id' => $data['folder_id'] ?? null,
            'title' => $data['title'] ?? '',
            'color' => $data['color'] ?? 'default',
            'created_by' => $request->user()->id,
        ]);

        // Creator owns the share (can manage visibility/roles); defaults to `family` visibility.
        $note->shares()->create(['user_id' => $request->user()->id, 'role' => 'owner']);

        return to_route('notes.show', $note);
    }

    public function show(Request $request, Note $note): Response
    {
        // Self-heal notes created before an owner share existed. Idempotent.
        if (! $note->shares()->where('role', 'owner')->exists()) {
            $note->shares()->firstOrCreate(['user_id' => $note->created_by], ['role' => 'owner']);
        }

        abort_unless($this->access->canView($request->user(), $note), 403);

        // Mark this note as "just opened" for the dashboard's Pick-up-where-you-left-off.
        EntityVisit::record('note', $note->id);

        $family = Family::findOrFail(CurrentFamily::id());

        return Inertia::render('notes/note', [
            'note' => [
                'id' => $note->id,
                'title' => $note->title,
                'body' => $note->body,
                'color' => $note->color,
                'pinned' => $note->pinned,
                'folderId' => $note->folder_id,
                'visibility' => $note->visibility(),
                'canEdit' => $this->access->roleFor($request->user(), $note) !== null,
            ],
            'folders' => Folder::query()->orderBy('position')->latest('id')
                ->get(['id', 'name', 'color'])->map(fn ($f) => [
                    'id' => $f->id, 'name' => $f->name, 'color' => $f->color,
                ])->values(),
            'share' => $this->shareState($note, $family),
        ]);
    }

    public function updateNote(Request $request, Note $note): RedirectResponse
    {
        abort_unless($this->access->roleFor($request->user(), $note) !== null, 403);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'body' => ['sometimes', 'string'],
            'color' => ['sometimes', 'string', 'max:20'],
            'pinned' => ['sometimes', 'boolean'],
            'folder_id' => ['sometimes', 'nullable', 'integer', 'exists:notes_folders,id'],
            'visibility' => ['sometimes', 'in:family,invited,private'],
        ]);

        $note->fill(array_intersect_key($data, array_flip(['title', 'body', 'color', 'pinned', 'folder_id'])))->save();

        if (array_key_exists('visibility', $data)) {
            $note->setVisibility($data['visibility']);
        }

        return back();
    }

    public function destroyNote(Request $request, Note $note): RedirectResponse
    {
        abort_unless($note->created_by === $request->user()->id, 403);
        $note->delete();

        return to_route('notes.index');
    }

    public function storeFolder(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:20'],
        ]);

        Folder::create([
            'name' => $data['name'],
            'color' => $data['color'] ?? 'gray',
            'position' => (int) Folder::max('position') + 1,
            'created_by' => $request->user()->id,
        ]);

        return back();
    }

    public function updateFolder(Request $request, Folder $folder): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'color' => ['sometimes', 'string', 'max:20'],
        ]);
        $folder->fill($data)->save();

        return back();
    }

    public function destroyFolder(Request $request, Folder $folder): RedirectResponse
    {
        // Notes inside detach (folder_id -> null via nullOnDelete), they are not deleted.
        $folder->delete();

        return back();
    }

    // Build the ShareState the core ShareSheet expects (visibility, per-member roles, public link).
    private function shareState(Note $note, Family $family): array
    {
        $visibility = $note->visibility();
        $sharesByUser = $note->shares->keyBy('user_id');
        $link = $note->shareLinks()->first();

        return [
            'type' => 'note',
            'id' => $note->id,
            'visibility' => $visibility,
            'canManage' => $this->access->canManage(request()->user(), $note),
            'members' => $family->members->map(function ($m) use ($sharesByUser, $visibility) {
                $explicit = $sharesByUser->get($m->id)?->role;

                return [
                    'id' => $m->id,
                    'name' => $m->name,
                    'role' => $explicit ?? ($visibility === 'family' ? 'editor' : null),
                    'isOwner' => $explicit === 'owner',
                ];
            })->values(),
            'link' => $link ? ['id' => $link->id, 'token' => $link->token, 'url' => route('share.public', $link->token)] : null,
        ];
    }
}
