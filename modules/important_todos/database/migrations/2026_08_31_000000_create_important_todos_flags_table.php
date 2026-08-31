<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Table name is prefixed with the module id (`important_todos_`) so `modules:migrate`'s
        // ModuleNamespace guard accepts it. This is why the module id uses an underscore, not a hyphen.
        Schema::create('important_todos_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            // One flag per task. The FK to the tasks primitive cascades on delete, so uninstall/data
            // cleanup is automatic: deleting a task drops its flag row (referential safety, §8.6).
            $table->foreignId('task_id')->constrained('tasks_tasks')->cascadeOnDelete();
            $table->boolean('important')->default(false);
            $table->timestamps();
            $table->unique('task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('important_todos_flags');
    }
};
