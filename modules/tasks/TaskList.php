<?php

namespace Modules\Tasks;

use App\Support\Sharing\Shareable;
use App\Support\Tenancy\BelongsToFamily;
use App\Support\Trash\CascadesTrash;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaskList extends Model
{
    use BelongsToFamily;
    // shares() + visibility()/roleFor() come from here

    // Deleting a list goes to the 30-day trash and takes its tasks along (see CascadesTrash).
    use CascadesTrash, SoftDeletes;
    use Shareable;

    protected array $trashChildren = ['tasks'];

    protected $table = 'tasks_lists';

    protected $fillable = ['name', 'color', 'icon', 'archived_at', 'position', 'created_by'];

    protected $casts = ['archived_at' => 'datetime'];

    // Every task in the list, sub-tasks included. FK is the conventional task_list_id (TaskList).
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
