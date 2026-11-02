<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Repeat picker (design j6QDy4 T4) "Count next from": 'due' (the next one follows the due date, the old behaviour)
// or 'done' (it follows the day it actually got done: done late on Nov 2, every 3 months, next is Feb 2).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_todos_rules', function (Blueprint $table) {
            $table->string('count_from', 8)->default('due');
        });
    }

    public function down(): void
    {
        Schema::table('recurring_todos_rules', fn (Blueprint $table) => $table->dropColumn('count_from'));
    }
};
