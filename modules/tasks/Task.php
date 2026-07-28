<?php

namespace Modules\Tasks;

use App\Models\User;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    use BelongsToFamily;

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

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
