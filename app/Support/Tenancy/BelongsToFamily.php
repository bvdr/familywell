<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

// Any tenant-owned model uses this. Requires a `family_id` column.
trait BelongsToFamily
{
    protected static function bootBelongsToFamily(): void
    {
        static::addGlobalScope('family', function (Builder $builder) {
            if ($id = CurrentFamily::id()) {
                $builder->where($builder->getModel()->getTable().'.family_id', $id);
            }
        });

        static::creating(function (Model $model) {
            if (empty($model->family_id) && $id = CurrentFamily::id()) {
                $model->family_id = $id;
            }
        });
    }
}
