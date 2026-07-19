<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Payment sources a spend can be "paid with" (e.g. Wise/card, cash Oana/cash). Family-scoped and
// reusable across events; a spend optionally references one.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spending_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id');
            $table->string('name');
            $table->string('type')->default('card'); // cash | card | bank | other
            $table->timestamps();
        });

        Schema::table('spending_spends', function (Blueprint $table) {
            // Nullable: existing spends have no source, and deleting a source just clears it.
            $table->foreignId('source_id')->nullable()->after('payer_id')
                ->constrained('spending_sources')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('spending_spends', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_id');
        });
        Schema::dropIfExists('spending_sources');
    }
};
