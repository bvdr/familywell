<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The family calendar. An event is all-day when start_time/end_time are null. Dates + times are the wall-clock
// values the person typed (no per-family timezone exists yet), so nothing is converted on read or write.
// Repeats are a rule on the row (repeat/repeat_interval/repeat_until), expanded on read by Occurrences; no
// occurrence rows are stored. Links (people, to-dos, project) follow the projects_links shape: polymorphic,
// no FK on linkable_id, cleaned up by hooks (person.deleted) or ignored on read when the target is gone.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('notes')->nullable();
            $table->string('icon', 40)->nullable();
            $table->string('color', 20)->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('start_time', 5)->nullable(); // 'H:i'; string, not time, so sqlite/pgsql agree
            $table->string('end_time', 5)->nullable();
            $table->string('repeat', 10)->default('none');
            $table->unsignedSmallInteger('repeat_interval')->default(1);
            $table->date('repeat_until')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes(); // 30-day trash
            $table->index(['family_id', 'start_date']);
        });

        Schema::create('calendar_event_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->string('linkable_type', 20); // user | person | task | project
            $table->unsignedBigInteger('linkable_id');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['event_id', 'linkable_type', 'linkable_id']);
            $table->index(['linkable_type', 'linkable_id']);
        });

        // One private ICS subscribe token per user per family. Stored as-is (like guest-link tokens) so the
        // URL can be shown again; "reset" rotates it and the old URL stops working.
        Schema::create('calendar_feeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamps();
            $table->unique(['family_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_feeds');
        Schema::dropIfExists('calendar_event_links');
        Schema::dropIfExists('calendar_events');
    }
};
