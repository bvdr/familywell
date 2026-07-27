<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Brings any already-migrated environment (prod, where the original flat `shopping_items` migration
// is already recorded and therefore won't re-run) up to the multi-list schema. Idempotent and
// guarded so it is a no-op on fresh installs, where the rewritten create-migration already built the
// target tables. No data to preserve (the old flat list was scaffolding), so a stale `shopping_items`
// is dropped and recreated rather than altered column-by-column.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shopping_lists')) {
            Schema::create('shopping_lists', function (Blueprint $table) {
                $table->id();
                $table->foreignId('family_id');
                $table->string('name');
                $table->string('store')->nullable();
                $table->string('color')->default('green');
                $table->string('icon')->default('cart');
                $table->timestamp('archived_at')->nullable();
                $table->unsignedInteger('position')->default(0);
                $table->foreignId('created_by');
                $table->timestamps();
            });
        }

        // Old flat table present (no list foreign key) => replace it with the new shape.
        if (Schema::hasTable('shopping_items') && ! Schema::hasColumn('shopping_items', 'shopping_list_id')) {
            Schema::dropIfExists('shopping_items');
        }

        if (! Schema::hasTable('shopping_items')) {
            Schema::create('shopping_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('family_id');
                $table->foreignId('shopping_list_id')->constrained('shopping_lists')->cascadeOnDelete();
                $table->string('name');
                $table->string('quantity')->nullable();
                $table->string('department')->default('other');
                $table->boolean('bought')->default(false);
                $table->string('note')->nullable();
                $table->foreignId('assigned_to')->nullable();
                $table->unsignedInteger('position')->default(0);
                $table->foreignId('created_by');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Non-reversible bridge migration; the base create-migration owns the schema teardown.
    }
};
