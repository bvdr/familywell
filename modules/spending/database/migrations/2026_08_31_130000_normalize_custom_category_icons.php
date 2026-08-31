<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Custom categories used to store the PascalCase component name 'Tag' as their fallback icon. Icons are
// now chosen via the IconPicker and stored as kebab-case lucide KEYS, so normalise that legacy fallback
// to the real key 'tag'. This lets the picker trigger show the current icon and the dynamic renderer
// resolve it. Built-in categories keep their curated PascalCase names — they render via a static map —
// so this only touches custom rows.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('spending_categories')
            ->where('is_default', false)
            ->where('icon', 'Tag')
            ->update(['icon' => 'tag']);
    }

    public function down(): void
    {
        DB::table('spending_categories')
            ->where('is_default', false)
            ->where('icon', 'tag')
            ->update(['icon' => 'Tag']);
    }
};
