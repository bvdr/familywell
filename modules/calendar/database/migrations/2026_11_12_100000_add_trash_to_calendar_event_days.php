<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Marks and day notes join the 30-day trash: the MCP tools can delete them, and every MCP delete is reversible
// (FamilywellServer's instructions promise it). The web app's deletes go the same way, so both agree.
return new class extends Migration
{
    public function up(): void
    {
        foreach (['calendar_event_marks', 'calendar_event_day_notes'] as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->softDeletes());
        }
    }

    public function down(): void
    {
        foreach (['calendar_event_marks', 'calendar_event_day_notes'] as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->dropSoftDeletes());
        }
    }
};
