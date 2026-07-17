<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Optional receipt photo per spend, stored in R2; this holds the object key.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spending_spends', function (Blueprint $table) {
            $table->string('receipt_path')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('spending_spends', function (Blueprint $table) {
            $table->dropColumn('receipt_path');
        });
    }
};
