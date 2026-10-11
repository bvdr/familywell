<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Count in monthly spending": a list-level setting (default ON, so every existing list keeps counting)
// plus a per-expense override. The expense column is nullable on purpose: null = follow the list
// (decided 2026-10-11: inherit unless overridden), so flipping a list moves all its non-overridden
// expenses in or out of the monthly figures. Only an explicit true/false on the expense pins it.
// Lists created from a project start OFF (ProjectsController::createSpendList); expenses still count in
// the list's own total/stats/settle-up either way. See Spend::scopeCountsInMonthly.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spending_events', function (Blueprint $table) {
            $table->boolean('count_in_monthly')->default(true);
        });
        Schema::table('spending_spends', function (Blueprint $table) {
            $table->boolean('count_in_monthly')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('spending_events', fn (Blueprint $table) => $table->dropColumn('count_in_monthly'));
        Schema::table('spending_spends', fn (Blueprint $table) => $table->dropColumn('count_in_monthly'));
    }
};
