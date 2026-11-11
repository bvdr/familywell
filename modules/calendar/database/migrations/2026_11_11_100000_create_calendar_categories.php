<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Family-defined event categories (design C14), next to the five built-ins (CalendarCategory::BUILT_IN), which live in
// code so their names stay translated and need no seeding. Events keep their `category` string column and refer to a
// custom one by `slug` (not a FK, like spending categories), so deleting a category can never break an event row:
// CalendarCategory::destroy clears it from the events. The slug is unique per family and at most 20 characters,
// the width of calendar_events.category.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id');
            $table->string('slug', 20);
            $table->string('name', 40);
            $table->string('icon', 40)->default('tag'); // lucide key: the icon its events start with
            $table->string('color', 20);                // a palette key (lib/tasks LIST_COLORS)
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['family_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_categories');
    }
};
