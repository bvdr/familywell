<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Idempotency ledger for the "it's X's birthday today" push: the date the family was last notified
// about this birthday. The daily command skips rows already stamped today, so a re-run (manual,
// scheduler hiccup) never sends the same wish twice.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('birthdays_dates', function (Blueprint $table) {
            $table->date('notified_on')->nullable()->after('birthdate');
        });
    }

    public function down(): void
    {
        Schema::table('birthdays_dates', function (Blueprint $table) {
            $table->dropColumn('notified_on');
        });
    }
};
