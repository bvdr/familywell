<?php

namespace Modules\Projects;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectLink extends Model
{
    use BelongsToFamily;

    protected $table = 'projects_links';

    protected $fillable = ['project_id', 'linkable_type', 'linkable_id'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
