<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Global: a row here means the module's migrations have run (installed).
        Schema::create('modules', function (Blueprint $table) {
            $table->string('module_id')->primary();
            $table->string('version');
        });

        // Per-family enablement. A flip here is never DDL (spec 12.2).
        Schema::create('family_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->string('module_id');
            $table->boolean('enabled')->default(true);
            $table->unique(['family_id', 'module_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_modules');
        Schema::dropIfExists('modules');
    }
};
