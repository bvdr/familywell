<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tasks: shared to-dos grouped into lists (mirrors the shopping module), with one level of sub-tasks.
// An individual task can also be linked into a project via the projects module's polymorphic links.
// Tables are prefixed `tasks_` per the module namespace rule. List visibility rides the core sharing
// system (TaskList uses the Shareable trait), so tasks inherit their list's access.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id');
            $table->string('name');
            $table->string('color')->default('green');
            $table->string('icon')->default('list');
            $table->timestamp('archived_at')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by');
            $table->timestamps();
        });

        Schema::create('tasks_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id');
            // Nullable: a task lives EITHER in a to-do list (task_list_id set) OR individually in a
            // project (task_list_id null + a projects_links row owned by the projects module). Deleting
            // the list cascades its tasks; project tasks survive independently of any list.
            $table->foreignId('task_list_id')->nullable()->constrained('tasks_lists')->cascadeOnDelete();
            // Self-reference for sub-tasks. Only ONE level deep is allowed (enforced in the controller:
            // a task that already has a parent may not itself be a parent). Deleting a parent cascades
            // to its children.
            $table->foreignId('parent_id')->nullable()->constrained('tasks_tasks')->cascadeOnDelete();
            $table->string('title');
            $table->string('note')->nullable();
            $table->boolean('done')->default(false);
            $table->date('due_date')->nullable();
            $table->foreignId('assigned_to')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks_tasks');
        Schema::dropIfExists('tasks_lists');
    }
};
