<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The Inventory module's schema. Everything is family-scoped (BelongsToFamily). Categories and
// locations follow the spending-categories pattern: they are referenced BY KEY on an item (a plain
// string column, not an FK), so a category/location can be seeded, renamed, added or removed without
// ever orphaning an item — an unknown key just renders via the client's fallback icon/label. Default
// categories/locations are seeded lazily on first read (…::ensureDefaults), so this migration is pure
// schema and safe under the pooled config. Item STATE (good/low/empty/expiring/expired/unknown) is
// derived in code from quantity/min/expiry — it is never a stored column.
return new class extends Migration
{
    public function up(): void
    {
        // Family-definable categories (Food, Drinks, Cleaning, …). Icons are kebab-case lucide keys
        // (the IconPicker's vocabulary), rendered directly by <LucideIcon>.
        Schema::create('inventory_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->string('key');                          // slug referenced by items (e.g. food, or a custom slug)
            $table->string('name');                         // English/default label for defaults, entered name for custom
            $table->string('icon')->default('package');     // kebab-case lucide key
            $table->string('color', 9)->default('#9A8C7A');
            $table->boolean('is_default')->default(false);  // seeded default (label localised via i18n) vs custom
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            // One row per key per family; lets ensureDefaults() seed with insertOrIgnore safely.
            $table->unique(['family_id', 'key']);
        });

        // Family-definable storage locations (Kitchen, Pantry, Fridge, …). Same key-referenced shape.
        Schema::create('inventory_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->string('icon')->default('map-pin');     // kebab-case lucide key
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['family_id', 'key']);
        });

        // The thing a family keeps/consumes/replenishes. `quantity` NULL means the "unknown" state — a
        // first-class state so users aren't forced to measure everything. `category`/`location` hold a
        // category/location KEY (see above). Quantities are decimals so grams/ml work as well as pieces.
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('quantity', 12, 2)->nullable();          // NULL => "unknown" state
            $table->string('unit')->default('pieces');               // pieces/g/kg/ml/L/packs/bottles/boxes
            $table->string('category')->nullable();                  // category key
            $table->string('location')->nullable();                  // location key
            $table->decimal('min_quantity', 12, 2)->default(0);      // at/below => "low" (0 disables the low threshold)
            $table->decimal('target_quantity', 12, 2)->nullable();   // the "full" level; drives replenishment + the qty bar
            $table->date('expires_at')->nullable();                  // drives expiring/expired
            $table->boolean('auto_replenish')->default(true);        // add to Shopping when low (the core loop, wired in P4)
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['family_id', 'category']);
        });

        // Append-only history of every quantity change. `delta` is the signed change (null for a pure
        // adjustment to an absolute value), `type` labels why. Drives "Recently updated" + item history.
        Schema::create('inventory_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->decimal('delta', 12, 2)->nullable();             // signed change; null for an absolute adjust
            $table->string('type');                                  // added/consumed/adjusted/purchased
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['family_id', 'created_at']);
            $table->index('inventory_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_log');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('inventory_locations');
        Schema::dropIfExists('inventory_categories');
    }
};
