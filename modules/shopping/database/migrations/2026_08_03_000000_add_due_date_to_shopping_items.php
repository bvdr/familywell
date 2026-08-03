<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Optional due date on a grocery item, so time-sensitive items ("cake for Saturday") surface in the
// dashboard's Today / This Week alongside tasks.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopping_items', function (Blueprint $table) {
            $table->date('due_date')->nullable()->after('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::table('shopping_items', function (Blueprint $table) {
            $table->dropColumn('due_date');
        });
    }
};
