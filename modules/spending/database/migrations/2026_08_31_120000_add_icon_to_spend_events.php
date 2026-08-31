<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A per-list lucide icon key (kebab-case, e.g. 'plane', 'car', 'utensils'), chosen with the reusable
// IconPicker. Nullable = no choice yet, which the UI renders as the module's default Wallet icon, so
// every pre-existing list keeps its current look. Length 64 comfortably fits the longest lucide name.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spending_events', function (Blueprint $table) {
            $table->string('icon', 64)->nullable()->after('display_currency');
        });
    }

    public function down(): void
    {
        Schema::table('spending_events', fn (Blueprint $table) => $table->dropColumn('icon'));
    }
};
