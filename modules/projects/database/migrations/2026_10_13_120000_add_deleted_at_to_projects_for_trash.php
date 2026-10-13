<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Reversible delete: deleting a project moves it (and its tasks) to the 30-day trash instead of erasing it.
// Purged by `trash:purge`. See App\Support\Trash\Trash.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects_projects', fn (Blueprint $table) => $table->softDeletes());
    }

    public function down(): void
    {
        Schema::table('projects_projects', fn (Blueprint $table) => $table->dropSoftDeletes());
    }
};
