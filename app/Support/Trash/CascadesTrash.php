<?php

namespace App\Support\Trash;

use Illuminate\Database\Eloquent\Model;

/**
 * Soft-delete cascade for parent/child models (a list and its items, a task and its sub-tasks).
 * DB cascades only fire on a real DELETE, and modules query child models directly (the dashboard agenda
 * reads Task::query()), so trashing a parent must trash its live children too, or they would keep showing.
 *
 *  - trash a parent  -> its live children get the parent's exact deleted_at (one SQL UPDATE)
 *  - restore a parent -> children whose deleted_at equals the parent's come back; children that were
 *                        trashed on their own earlier stay in the trash
 *  - restore a child -> its trashed parent is restored first (a task can't come back into a deleted list)
 *
 * The host model uses SoftDeletes and declares, as relation method names:
 *   protected array $trashChildren = ['tasks'];   // HasMany relations to trash along
 *   protected array $trashParents = ['list'];     // BelongsTo relations to restore along
 * Child relations must be plain (no orderBy): Postgres UPDATE has no ORDER BY.
 */
trait CascadesTrash
{
    public static function bootCascadesTrash(): void
    {
        static::softDeleted(function (Model $model) {
            foreach ($model->trashChildren ?? [] as $relation) {
                $model->{$relation}()->update([$model->getDeletedAtColumn() => $model->{$model->getDeletedAtColumn()}]);
            }
        });

        static::restoring(function (Model $model) {
            // Still the old timestamp here: restore() fires `restoring` before clearing it.
            $deletedAt = $model->{$model->getDeletedAtColumn()};
            foreach ($model->trashParents ?? [] as $relation) {
                $parent = $model->{$relation}()->withTrashed()->first();
                if ($parent?->trashed()) {
                    $parent->restore();
                }
            }
            foreach ($model->trashChildren ?? [] as $relation) {
                $model->{$relation}()->onlyTrashed()->where($model->getDeletedAtColumn(), $deletedAt)->restore();
            }
        });
    }
}
