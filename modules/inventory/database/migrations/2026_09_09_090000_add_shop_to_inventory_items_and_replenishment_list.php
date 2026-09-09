<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Two refinements to the Inventory -> Shopping loop:
//
//  1. inventory_items.shop — the shop the family buys this item from, stored the way Shopping stores it
//     (a plain shop NAME, exactly like shopping_items.store; ShoppingShop::ensure keeps a shop row for
//     it). When the item is surfaced onto Shopping the created row carries this as its store, so the
//     item lands under the right shop in the Shops view. Nullable: no shop chosen => plain line.
//
//  2. inventory_settings.shopping_list_id — the family's dedicated "Inventory" shopping list that
//     replenishment adds to (instead of whichever list happened to be the acting user's latest). A
//     PLAIN id, not a DB foreign key, for the same reason as inventory_items.shopping_item_id: the two
//     modules' schemas must stay independent (inventory migrates before shopping on a fresh install),
//     so the pointer's integrity is kept in code — InventoryReplenishment::list() re-verifies the list
//     still exists and isn't archived, and otherwise resolves by name / recreates it (never duplicates).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->string('shop')->nullable()->after('location');
        });
        Schema::table('inventory_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('shopping_list_id')->nullable()->after('auto_add_empty');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', fn (Blueprint $table) => $table->dropColumn('shop'));
        Schema::table('inventory_settings', fn (Blueprint $table) => $table->dropColumn('shopping_list_id'));
    }
};
