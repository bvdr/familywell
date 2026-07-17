<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Multi-currency: a spend records its own currency + original amount, plus base_amount (converted to
// the event's currency and frozen at capture). Balances are computed from base_amount.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spending_spends', function (Blueprint $table) {
            $table->string('currency', 3)->after('amount')->nullable();
            $table->bigInteger('base_amount')->after('currency')->nullable();
            $table->decimal('rate', 16, 8)->after('base_amount')->default(1);
        });

        // Backfill existing spends: they were already in the event's currency, 1:1.
        DB::table('spending_spends')->whereNull('currency')->update([
            'currency' => DB::raw('(select currency from spending_events where spending_events.id = spending_spends.spend_event_id)'),
            'base_amount' => DB::raw('amount'),
            'rate' => 1,
        ]);

        Schema::table('spending_spends', function (Blueprint $table) {
            $table->string('currency', 3)->nullable(false)->change();
            $table->bigInteger('base_amount')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('spending_spends', function (Blueprint $table) {
            $table->dropColumn(['currency', 'base_amount', 'rate']);
        });
    }
};
