<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A list's own Sort items and Bought items (List settings, design S2b). Null = follow the family's Shopping
// settings, so changing the default later still applies to every list that never picked its own (same as
// clean_after_days).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopping_lists', function (Blueprint $table) {
            $table->string('sort', 10)->nullable(); // aisle | az | added
            $table->string('bought', 10)->nullable(); // sink | hide
        });
    }

    public function down(): void
    {
        Schema::table('shopping_lists', fn (Blueprint $t) => $t->dropColumn(['sort', 'bought']));
    }
};
