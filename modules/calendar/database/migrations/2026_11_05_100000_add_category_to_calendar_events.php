<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// An event's category (design 04b C9/C10/D2: the All · Trips · Faith · Family · School · Work chips above the views).
// A fixed slug set at the time (since 2026-11-11 CalendarCategory adds family-defined ones next to these five): the design named exactly these, and the
// chips are a filter, so a short known list beats a settings screen. Nullable: existing events have none.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->string('category', 20)->nullable()->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
