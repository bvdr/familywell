<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Shopping is a multi-list grocery app: a family keeps several lists (Weekly Groceries, Party
// Supplies, ...), each holding items grouped by aisle. Tables are prefixed `shopping_` per the
// module namespace rule. Visibility ("who can view") rides the core sharing system, so there is no
// visibility column here.
return new class extends Migration
{
    public function up(): void
    {
        // Drop the old flat scaffolding table if a previous version of the module created it. There is
        // no real data (confirmed), so this is a clean replace rather than a data migration.
        Schema::dropIfExists('shopping_items');

        Schema::create('shopping_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id');
            $table->string('name');
            $table->string('store')->nullable();      // free-text store label ("Whole Foods")
            $table->string('color')->default('green'); // swatch slug, see lib/list-appearance
            $table->string('icon')->default('cart');   // icon slug from the global pack
            $table->timestamp('archived_at')->nullable();
            $table->unsignedInteger('position')->default(0); // manual ordering in the index
            $table->foreignId('created_by');
            $table->timestamps();
        });

        Schema::create('shopping_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id');
            $table->foreignId('shopping_list_id')->constrained('shopping_lists')->cascadeOnDelete();
            $table->string('name');
            $table->string('quantity')->nullable();       // free-text ("2 bunches", "1 gallon")
            $table->string('department')->default('other'); // aisle slug for grouping
            $table->boolean('bought')->default(false);
            $table->string('note')->nullable();
            $table->foreignId('assigned_to')->nullable(); // family member this item is for (avatar badge)
            $table->unsignedInteger('position')->default(0); // manual ordering within an aisle
            $table->foreignId('created_by');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopping_items');
        Schema::dropIfExists('shopping_lists');
    }
};
