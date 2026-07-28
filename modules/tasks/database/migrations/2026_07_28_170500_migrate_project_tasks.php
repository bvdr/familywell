<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// One-time data migration: the projects module used to keep its own flat `projects_tasks`. Tasks are
// now unified under the tasks module, so each native project task becomes a listless tasks-module task
// (task_list_id null) linked back to its project via a `task` row in projects_links.
//
// Idempotent at project granularity: a project that already has any linked `task` row is skipped, so a
// re-run (or a resume after a crash mid-way) never duplicates. Each project migrates in its own
// transaction, so a project is either fully migrated or not at all. The old projects_tasks rows are
// left in place (inert) rather than dropped.
return new class extends Migration
{
    public function up(): void
    {
        // Nothing to migrate on a fresh install (projects has no native tasks yet) or if projects
        // is not installed at all.
        if (! Schema::hasTable('projects_projects') || ! Schema::hasTable('projects_tasks') || ! Schema::hasTable('tasks_tasks')) {
            return;
        }

        foreach (DB::table('projects_projects')->get() as $project) {
            $alreadyMigrated = DB::table('projects_links')
                ->where(['project_id' => $project->id, 'linkable_type' => 'task'])
                ->exists();
            if ($alreadyMigrated) {
                continue;
            }

            $native = DB::table('projects_tasks')
                ->where('project_id', $project->id)
                ->orderBy('position')->orderBy('id')->get();
            if ($native->isEmpty()) {
                continue;
            }

            DB::transaction(function () use ($project, $native) {
                foreach ($native as $t) {
                    $taskId = DB::table('tasks_tasks')->insertGetId([
                        'family_id' => $project->family_id,
                        'task_list_id' => null,
                        'parent_id' => null,
                        'title' => $t->name,
                        'note' => null,
                        'done' => $t->done,
                        'due_date' => null,
                        'assigned_to' => $t->assigned_to,
                        'position' => $t->position,
                        'created_by' => $t->created_by,
                        'created_at' => $t->created_at,
                        'updated_at' => $t->updated_at,
                    ]);
                    DB::table('projects_links')->insert([
                        'family_id' => $project->family_id,
                        'project_id' => $project->id,
                        'linkable_type' => 'task',
                        'linkable_id' => $taskId,
                        'created_at' => $t->created_at,
                        'updated_at' => $t->updated_at,
                    ]);
                }
            });
        }
    }

    public function down(): void
    {
        // Non-reversible data bridge.
    }
};
