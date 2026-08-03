<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A shop is now a first-class entity (was a free-text store name on lists/items). It owns the aisle
// layout and gives the "Shops" view: everything you need at that shop across every list you can see.
// Items/lists still carry the store NAME (matched to a shop by name); the entity adds identity,
// an icon, and a home for the aisles + rename. `shop_id` is added to aisles so a shop's aisle set
// survives a rename.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopping_shops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id');
            $table->string('name');
            $table->string('icon')->default('store'); // slug from the frontend store-icon pack
            $table->string('color')->default('green');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            // One shop per name within a family; the store name on items/lists resolves to it.
            $table->unique(['family_id', 'name']);
        });

        Schema::table('shopping_aisles', function (Blueprint $table) {
            // Which shop this aisle belongs to. Nullable during backfill; still keyed by `store` name
            // too so the existing per-list grouping keeps working through the transition.
            $table->foreignId('shop_id')->nullable()->after('family_id');
        });
    }

    public function down(): void
    {
        Schema::table('shopping_aisles', function (Blueprint $table) {
            $table->dropColumn('shop_id');
        });
        Schema::dropIfExists('shopping_shops');
    }
};
