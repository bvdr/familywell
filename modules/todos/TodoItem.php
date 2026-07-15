<?php

namespace Modules\Todos;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TodoItem extends Model
{
    use BelongsToFamily;

    protected $table = 'todos_items';

    protected $fillable = ['title', 'done', 'visibility', 'created_by'];

    protected $casts = ['done' => 'boolean'];

    // Shared items are visible to everyone in the family; private items only to their creator.
    public function scopeVisibleTo(Builder $query, int $userId): Builder
    {
        return $query->where(fn ($q) => $q->where('visibility', 'shared')->orWhere('created_by', $userId));
    }
}
