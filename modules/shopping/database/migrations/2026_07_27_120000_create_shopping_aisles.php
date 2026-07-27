<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Per-shop aisles. A "shop" is the free-text store name on a list (e.g. "Metro", "Dedeman"), so aisles
// are keyed by (family, store). A shop with no rows falls back to the default Grocery set in code, so
// existing lists keep grouping without a data migration. Items reference an aisle by its `slug`.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopping_aisles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id');
            $table->string('store');                  // the shop name, matches shopping_lists.store
            $table->string('slug');                   // stable id of the aisle within the shop
            $table->string('name');
            $table->string('icon')->default('basket'); // slug from the frontend AISLE_ICONS pack
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['family_id', 'store', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopping_aisles');
    }
};
