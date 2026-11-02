<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Tasks redesign (design j6QDy4): the overview's list cards say who last touched a list and when ("D · 12 MIN AGO"),
// sorted by it; a list's folded Done row says the last one done and by whom ("last: Mow lawn, by David").
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks_lists', function (Blueprint $table) {
            $table->timestamp('touched_at')->nullable()->index();
            $table->foreignId('touched_by')->nullable();
        });
        Schema::table('tasks_tasks', function (Blueprint $table) {
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable();
        });

        // Best guess for what's there: a done task was done when it last changed; a list was last touched when it or
        // any of its tasks last changed, by its creator (who did it isn't known before now).
        DB::table('tasks_tasks')->where('done', true)->update(['done_at' => DB::raw('updated_at')]);
        DB::table('tasks_lists')->update([
            'touched_by' => DB::raw('created_by'),
            'touched_at' => DB::raw('COALESCE((SELECT MAX(t.updated_at) FROM tasks_tasks t WHERE t.task_list_id = tasks_lists.id), tasks_lists.updated_at)'),
        ]);
        // A list changed after its last task: the list's own time.
        DB::table('tasks_lists')->whereColumn('updated_at', '>', 'touched_at')->update(['touched_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('tasks_lists', fn (Blueprint $table) => $table->dropColumn(['touched_at', 'touched_by']));
        Schema::table('tasks_tasks', fn (Blueprint $table) => $table->dropColumn(['done_at', 'done_by']));
    }
};
