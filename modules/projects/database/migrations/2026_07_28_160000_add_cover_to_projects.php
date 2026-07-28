<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Optional cover photo for a project. `cover_path` is the R2 object key (family-namespaced), served
// through an auth-gated route (same pattern as spend receipts).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects_projects', function (Blueprint $table) {
            $table->string('cover_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects_projects', function (Blueprint $table) {
            $table->dropColumn('cover_path');
        });
    }
};
