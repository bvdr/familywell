<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Where the money was spent (shop / merchant name). Stored as a plain string rather than a FK to
// shopping_shops so Spending never depends on the Shopping module being enabled; the form suggests
// existing names (from both modules) so spelling stays consistent. Nullable: older spends have none.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spending_spends', function (Blueprint $table) {
            $table->string('store', 120)->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('spending_spends', fn (Blueprint $table) => $table->dropColumn('store'));
    }
};
