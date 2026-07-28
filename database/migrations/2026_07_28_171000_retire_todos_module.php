<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The old `todos` module (a flat, single-level list) is superseded by the richer `tasks` module.
// Its files are removed from the codebase; this drops its table and clears its registry rows so it
// stops appearing in the modules screen or on any family. Idempotent: safe to re-run.
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('todos_items');
        DB::table('family_modules')->where('module_id', 'todos')->delete();
        DB::table('modules')->where('module_id', 'todos')->delete();
    }

    public function down(): void
    {
        // Non-reversible: the todos module no longer exists.
    }
};
