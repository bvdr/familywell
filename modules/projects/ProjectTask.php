<?php

namespace Modules\Projects;

use App\Models\User;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectTask extends Model
{
    use BelongsToFamily;

    protected $table = 'projects_tasks';

    protected $fillable = ['project_id', 'name', 'assigned_to', 'done', 'position', 'created_by'];

    protected $casts = ['done' => 'boolean'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
