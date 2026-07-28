<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Items can now be bought at a chosen shop, with optional alternatives ("also available at").
// `store` is the primary shop (null => inherit the list's store); `alt_stores` is a JSON list of
// other shop names where the item can also be picked up (drives which store's focus run shows it).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopping_items', function (Blueprint $table) {
            $table->string('store')->nullable();
            $table->json('alt_stores')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shopping_items', function (Blueprint $table) {
            $table->dropColumn(['store', 'alt_stores']);
        });
    }
};
