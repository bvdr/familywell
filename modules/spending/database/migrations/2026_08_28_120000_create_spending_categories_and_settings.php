<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Makes expense categories family-definable and adds a per-family module settings row (currently just
// the default currency). Both are family-scoped. Existing spends keep their `category` key string
// (categories are referenced by key, not FK), so nothing back-references these rows — they only carry
// the label/icon/colour used to render a key. Default categories are seeded lazily on first read
// (SpendCategory::ensureDefaults), so this migration is pure schema and safe under the pooled config.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spending_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id');
            $table->string('key');                       // slug used on spends (e.g. food, education, or a custom slug)
            $table->string('name');                      // English/default label for defaults, the entered name for custom
            $table->string('icon')->default('Tag');      // lucide icon component name
            $table->string('color', 9)->default('#9A8C7A');
            $table->boolean('is_default')->default(false); // seeded default (label localised via i18n) vs custom
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            // One row per key per family; lets ensureDefaults() seed with insertOrIgnore safely.
            $table->unique(['family_id', 'key']);
        });

        Schema::create('spending_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->unique(); // one settings row per family
            $table->string('default_currency', 8)->nullable(); // null => fall back to app defaults
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spending_settings');
        Schema::dropIfExists('spending_categories');
    }
};
