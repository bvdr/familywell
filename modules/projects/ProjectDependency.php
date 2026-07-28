<?php

namespace Modules\Projects;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectDependency extends Model
{
    use BelongsToFamily;

    protected $table = 'projects_dependencies';

    protected $fillable = ['project_id', 'name', 'note', 'status', 'position', 'created_by'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
