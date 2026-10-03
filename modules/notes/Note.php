<?php

namespace Modules\Notes;

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

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }
}
