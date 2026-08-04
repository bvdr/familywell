<?php

namespace Modules\Notes;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// A folder is a family-wide bucket notes can be filed under. Folders are not individually shared
// (unlike notes); everyone in the family sees the same folders. Deleting a folder detaches its
// notes rather than deleting them (see the migration's nullOnDelete).
class Folder extends Model
{
    use BelongsToFamily;

    protected $table = 'notes_folders';

    protected $fillable = ['name', 'color', 'position', 'created_by'];

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }
}
