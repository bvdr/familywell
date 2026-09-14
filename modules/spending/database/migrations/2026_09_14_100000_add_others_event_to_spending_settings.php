<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// spending_settings.others_event_id — the family's default "Others" spending list, the one an expense
// lands on when it is added from the overview without picking a list (Spending became expense-first on
// 2026-09-14). Same idea as inventory_settings.shopping_list_id: a PLAIN id, not a DB foreign key, so an
// archived/deleted list just makes the pointer stale and SpendEvent::others() re-resolves (by name) or
// creates a fresh one instead of the row delete cascading into settings.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spending_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('others_event_id')->nullable()->after('default_currency');
        });
    }

    public function down(): void
    {
        Schema::table('spending_settings', fn (Blueprint $table) => $table->dropColumn('others_event_id'));
    }
};
