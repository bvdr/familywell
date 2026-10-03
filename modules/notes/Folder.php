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

    /**
     * The family folder called $name (case-insensitive), created at the end of the folder list when there
     * is none. Lets an assistant file a note "under Recipes" without managing folders separately.
     */
    public static function findOrCreateNamed(string $name, int $userId): self
    {
        $name = trim($name);

        return static::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()
            ?? static::create(['name' => $name, 'color' => 'gray', 'position' => (int) static::max('position') + 1, 'created_by' => $userId]);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }
}
