<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Which starting set of aisles a shop was given (StoreTemplates key): the "Started from" row on its aisles page
// (design S1f). Null = never picked (it shows the Grocery set it falls back to).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopping_shops', function (Blueprint $table) {
            $table->string('template', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shopping_shops', function (Blueprint $table) {
            $table->dropColumn('template');
        });
    }
};
