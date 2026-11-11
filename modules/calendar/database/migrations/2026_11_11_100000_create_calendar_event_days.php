<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Days inside an event" (design C15-C17): a 40-day fast has 40 days worth writing on.
//
// Marks (calendar_event_marks): a named, icon'd label ("Dezlegare la peste") on a SET of days. The days are stored as
// DAY INDEXES counted from the event's start (0 = first day), not dates, so a repeating event's next occurrence
// inherits them ("saved marks come back next year with the fast"). One json column per mark instead of a row per
// marked day: a mark is always read and written as a whole (the Mark days sheet saves every day in one request), and
// the calendar reads all marks of the visible events in one query without a join.
//
// Day notes (calendar_event_day_notes): a note about ONE specific date, so it is keyed by event + real date and does
// not repeat with the event. Both tables cascade with their event (trash keeps them: the event row stays until purged).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_event_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('icon', 40)->nullable();
            $table->json('days'); // list<int> day indexes from the event's start
            $table->timestamps();
            $table->index('event_id');
        });

        Schema::create('calendar_event_day_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('calendar_events')->cascadeOnDelete();
            $table->date('date'); // read as the plain 'Y-m-d' string (no cast), like the events' wall-clock dates elsewhere
            $table->text('body');
            $table->timestamps();
            $table->unique(['event_id', 'date']); // one note per day; also the lookup index
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_event_day_notes');
        Schema::dropIfExists('calendar_event_marks');
    }
};
