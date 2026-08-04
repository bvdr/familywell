<?php

namespace Modules\Notes;

use App\Support\Sharing\Shareable;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Note extends Model
{
    use BelongsToFamily;
    use Shareable; // shares() + visibility()/roleFor() come from here

    protected $table = 'notes_notes';

    protected $fillable = ['folder_id', 'title', 'body', 'color', 'pinned', 'position', 'created_by'];

    protected $casts = ['pinned' => 'boolean'];

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }
}
