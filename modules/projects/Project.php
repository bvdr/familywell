<?php

namespace Modules\Projects;

use App\Models\User;
use App\Support\Sharing\Shareable;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use BelongsToFamily;
    use Shareable; // shares() + visibility()/roleFor()

    protected $table = 'projects_projects';

    protected $fillable = ['name', 'description', 'status', 'progress', 'color', 'owner_id', 'target_start', 'waiting_on', 'archived_at', 'position', 'created_by', 'cover_path'];

    protected $casts = ['target_start' => 'date', 'archived_at' => 'datetime'];

    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class);
    }

    public function dependencies(): HasMany
    {
        return $this->hasMany(ProjectDependency::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(ProjectLink::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
