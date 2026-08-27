<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The list's chosen display currency (the top-right selector). Cosmetic: base amounts stay frozen to
// `currency`; this only picks what currency the figures are shown in. Nullable = show in the base.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spending_events', function (Blueprint $table) {
            $table->string('display_currency', 8)->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('spending_events', fn (Blueprint $table) => $table->dropColumn('display_currency'));
    }
};
