<?php

namespace Modules\Tasks;

use App\Models\User;
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

    /**
     * Create a list owned by $user: the creator gets the `owner` share (manages visibility/roles), and the
     * list defaults to `family` visibility. One place for the rule so the web UI and MCP tools agree.
     */
    public static function createFor(User $user, array $attrs, ?string $visibility = null): self
    {
        $list = static::create([
            'name' => $attrs['name'],
            'color' => $attrs['color'] ?? 'green',
            'icon' => $attrs['icon'] ?? 'list',
            'created_by' => $user->id,
        ]);
        $list->shares()->create(['user_id' => $user->id, 'role' => 'owner']);
        if ($visibility) {
            $list->setVisibility($visibility);
        }

        return $list;
    }

    /**
     * Add a task (or a sub-task when parent_id is set) at the end of its group. Sub-tasks are one level
     * deep: the parent must live in this list and be top-level (422 otherwise). Shared by the web UI and
     * the MCP tools.
     */
    public function addTask(User $user, array $data): Task
    {
        $parentId = $data['parent_id'] ?? null;
        if ($parentId) {
            $parent = $this->tasks()->findOrFail($parentId);
            abort_if($parent->parent_id !== null, 422, 'Sub-tasks cannot have sub-tasks.');
        }

        return $this->tasks()->create([
            'parent_id' => $parentId,
            'title' => $data['title'],
            'note' => $data['note'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
            'position' => (int) $this->tasks()->where('parent_id', $parentId)->max('position') + 1,
            'created_by' => $user->id,
        ]);
    }
}
