<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The Inventory <-> Shopping core loop (P4). An inventory item that has been surfaced onto a shopping
// list keeps a nullable pointer to that OPEN shopping item. This one link is what makes the loop
// idempotent: while it is set, the item already has a live shopping row and must not create a second
// one; it is cleared again the moment that shopping item is marked bought (which also replenishes the
// stock), so the next time the item runs low it can be added afresh.
//
// This is a PLAIN id column, not a DB foreign key — on purpose, and consistent with the rest of this
// module (categories/locations are referenced by key, never FK'd). A hard FK would couple two
// independent modules' schemas and break a fresh module-ordered migration (inventory migrates before
// shopping, so `shopping_items` may not exist yet when this runs). Instead the link's integrity is kept
// in code: the bought listener clears it, and addToShopping re-verifies the shopping row still exists and
// isn't bought before treating the pointer as "already on a list", so a stale pointer can never block a
// legitimate re-add or point at a row that is gone.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->unsignedBigInteger('shopping_item_id')->nullable()->after('auto_replenish');
            $table->index('shopping_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropIndex(['shopping_item_id']);
            $table->dropColumn('shopping_item_id');
        });
    }
};
