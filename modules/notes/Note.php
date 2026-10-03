<?php

namespace Modules\Notes;

use App\Models\User;
use App\Support\Sharing\Shareable;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Note extends Model
{
    use BelongsToFamily;
    use Shareable; // shares() + visibility()/roleFor() come from here

    // Deleting a note goes to the 30-day trash. Folders are NOT trashed: deleting one only un-files its
    // notes (nullOnDelete), there is nothing to lose, and no MCP tool deletes folders.
    use SoftDeletes;

    protected $table = 'notes_notes';

    protected $fillable = ['folder_id', 'title', 'body', 'color', 'pinned', 'position', 'created_by'];

    protected $casts = ['pinned' => 'boolean'];

    /** Create a note owned by $user (owner share; `family` visibility by default). Web UI + MCP tools. */
    public static function createFor(User $user, array $attrs): self
    {
        $note = static::create([
            'folder_id' => $attrs['folder_id'] ?? null,
            'title' => $attrs['title'] ?? '',
            'body' => $attrs['body'] ?? '',
            'color' => $attrs['color'] ?? 'default',
            'created_by' => $user->id,
        ]);
        $note->shares()->create(['user_id' => $user->id, 'role' => 'owner']);

        return $note;
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }
}
