<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Other calendars (ICS / webcal URLs) shown read-only in the family calendar (issue #222).
// A subscription belongs to the person who added it; `shared` shows it to the whole family. The URL is stored
// encrypted (model cast) because a private ICS link (Google's "secret address") is as good as a password.
// Events are fetched hourly and stored already expanded (repeats, exceptions, timezones resolved by sabre/vobject)
// into wall-clock rows in the subscription's timezone, the same wall-clock shape calendar_events uses. Reads are
// then one indexed range query instead of parsing ICS on every page view.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->text('url'); // encrypted
            $table->string('color', 20)->nullable();
            $table->string('timezone', 64); // the adder's browser zone; imported times are shown in it
            $table->boolean('shared')->default(false);
            $table->boolean('enabled')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->string('error', 20)->nullable(); // last sync failure: blocked | unreachable | not_calendar
            $table->timestamps();
            $table->index(['family_id', 'user_id']);
        });

        Schema::create('calendar_subscription_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('calendar_subscriptions')->cascadeOnDelete();
            $table->string('title');
            $table->string('location')->nullable();
            $table->text('notes')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('start_time', 5)->nullable(); // 'H:i', null = all-day (as calendar_events)
            $table->string('end_time', 5)->nullable();
            $table->index(['subscription_id', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_subscription_events');
        Schema::dropIfExists('calendar_subscriptions');
    }
};
