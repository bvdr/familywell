<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Projects: the bigger things a family works on together. A project holds native task lists and
// dependencies ("blocked by"), and can link to entities from other modules (v1: shopping lists).
// All tables are prefixed `projects_` (module namespace rule; cf. spending_spends). Visibility rides
// the core sharing system.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('planning'); // planning | in_progress | blocked | done
            $table->unsignedTinyInteger('progress')->default(0); // 0-100, set manually
            $table->string('color')->default('green');
            $table->foreignId('owner_id')->nullable(); // a family member
            $table->date('target_start')->nullable();
            $table->string('waiting_on')->nullable(); // blocked reason shown on the card
            $table->timestamp('archived_at')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by');
            $table->timestamps();
        });

        Schema::create('projects_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id');
            $table->foreignId('project_id')->constrained('projects_projects')->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('assigned_to')->nullable();
            $table->boolean('done')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by');
            $table->timestamps();
        });

        Schema::create('projects_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id');
            $table->foreignId('project_id')->constrained('projects_projects')->cascadeOnDelete();
            $table->string('name');
            $table->string('note')->nullable();
            $table->string('status')->default('pending'); // pending | blocked | done
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by');
            $table->timestamps();
        });

        // Polymorphic link from a project to an entity in another module (v1: shopping_list).
        Schema::create('projects_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id');
            $table->foreignId('project_id')->constrained('projects_projects')->cascadeOnDelete();
            $table->string('linkable_type');
            $table->unsignedBigInteger('linkable_id');
            $table->timestamps();
            $table->unique(['project_id', 'linkable_type', 'linkable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects_links');
        Schema::dropIfExists('projects_dependencies');
        Schema::dropIfExists('projects_tasks');
        Schema::dropIfExists('projects_projects');
    }
};
