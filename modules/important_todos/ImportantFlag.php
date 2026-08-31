<?php

namespace Modules\ImportantTodos;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;

/**
 * The module's own extension table: one row per task that has been marked important. It hangs off
 * the `task` primitive by foreign key and never touches the tasks module's tables — the WordPress
 * post-meta pattern. `family_id` + the tenant scope keep it family-isolated like every other model.
 */
class ImportantFlag extends Model
{
    use BelongsToFamily;

    protected $table = 'important_todos_flags';

    protected $fillable = ['task_id', 'important', 'family_id'];

    protected $casts = ['important' => 'boolean'];
}
