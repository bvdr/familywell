<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Notes: markdown-lite notes grouped into user-made folders. Tables are prefixed `notes_` per the
// module namespace rule. A note rides the core sharing system (Note uses the Shareable trait) so
// each note is family/invited/private on its own; folders are just family-wide organization and are
// not individually shared. Deleting a folder detaches its notes (folder_id -> null), not deletes them.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id');
            $table->string('name');
            $table->string('color')->default('gray');
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by');
            $table->timestamps();
        });

        Schema::create('notes_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id');
            $table->foreignId('folder_id')->nullable()->constrained('notes_folders')->nullOnDelete();
            $table->string('title')->default('');
            // Markdown-lite: headings, bold, bullets and [ ] checkboxes. Stored raw, rendered on view.
            $table->text('body')->default('');
            $table->string('color')->default('default');
            $table->boolean('pinned')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->foreignId('created_by');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes_notes');
        Schema::dropIfExists('notes_folders');
    }
};
