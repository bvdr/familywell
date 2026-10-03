<?php

namespace Modules\Tasks;

use App\Models\User;
use App\Support\Tenancy\BelongsToFamily;
use App\Support\Trash\CascadesTrash;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use BelongsToFamily;

    // Deleting a task goes to the 30-day trash with its sub-tasks; restoring one brings back its trashed
    // parent task / list first (see CascadesTrash).
    use CascadesTrash, SoftDeletes;

    protected array $trashChildren = ['childTasks'];

    protected array $trashParents = ['list', 'parent'];

    protected $table = 'tasks_tasks';

    protected $fillable = ['task_list_id', 'parent_id', 'title', 'note', 'done', 'due_date', 'assigned_to', 'position', 'created_by'];

    protected $casts = ['done' => 'boolean', 'due_date' => 'date'];

    public function list(): BelongsTo
    {
        return $this->belongsTo(TaskList::class, 'task_list_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_id');
    }

    // One level only: sub-tasks never have sub-tasks of their own (enforced when creating).
    public function subtasks(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_id')->orderBy('position')->orderBy('id');
    }

    // Same as subtasks() minus the ordering: the trash cascade UPDATEs through it and Postgres UPDATE has no ORDER BY.
    public function childTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
