<?php

namespace Modules\Tasks;

use App\Support\Sharing\Shareable;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskList extends Model
{
    use BelongsToFamily;
    use Shareable; // shares() + visibility()/roleFor() come from here

    protected $table = 'tasks_lists';

    protected $fillable = ['name', 'color', 'icon', 'archived_at', 'position', 'created_by'];

    protected $casts = ['archived_at' => 'datetime'];

    // Every task in the list, sub-tasks included. FK is the conventional task_list_id (TaskList).
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }
}
