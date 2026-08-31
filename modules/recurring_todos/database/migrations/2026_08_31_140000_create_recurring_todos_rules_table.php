<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Prefixed with the module id (`recurring_todos_`) so `modules:migrate`'s namespace guard
        // accepts it — the reason the module id uses an underscore, not a hyphen.
        Schema::create('recurring_todos_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            // One rule per task; cascades away with the task (automatic cleanup, no orphans).
            $table->foreignId('task_id')->constrained('tasks_tasks')->cascadeOnDelete();
            $table->string('interval'); // daily | weekly | monthly | yearly
            $table->date('next_occurrence'); // the date the task next resets to "to do"
            $table->timestamps();
            $table->unique('task_id');
            // The scheduler queries "which rules are due?" every hour — index the date it filters on.
            $table->index('next_occurrence');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_todos_rules');
    }
};
