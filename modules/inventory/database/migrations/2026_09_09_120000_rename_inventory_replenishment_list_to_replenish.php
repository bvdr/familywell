<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The dedicated replenishment shopping list was renamed from "Inventory" to "Replenish"
// (InventoryReplenishment::LIST_NAME). Existing families already have an "Inventory" list minted by the
// old name, so without this the new name-fallback would mint a second list next to the old one.
//
// Which list gets renamed — conservative on purpose, so a user-made list that merely happens to be called
// "Inventory" is never touched:
//   1. Families with a tracked pointer (inventory_settings.shopping_list_id): rename THAT list, if it is
//      still literally named "Inventory". The pointer is left as is, so resolution keeps finding it.
//   2. Families with inventory settings but no pointer yet (resolved by name-fallback only): rename the
//      family's single live list named exactly "Inventory" — that is the one the fallback would have
//      adopted — and store the pointer so it is tracked from now on. Two such lists = ambiguous = skip.
// Idempotent: only rows still named "Inventory" are ever written, so a re-run is a no-op.
return new class extends Migration
{
    public function up(): void
    {
        // The two modules' schemas are independent (a fresh install may migrate inventory before shopping).
        if (! Schema::hasTable('shopping_lists') || ! Schema::hasTable('inventory_settings')) {
            return;
        }

        foreach (DB::table('inventory_settings')->get(['id', 'family_id', 'shopping_list_id']) as $settings) {
            if ($settings->shopping_list_id) {
                DB::table('shopping_lists')
                    ->where('id', $settings->shopping_list_id)
                    ->where('family_id', $settings->family_id)
                    ->where('name', 'Inventory')
                    ->update(['name' => 'Replenish']);

                continue;
            }

            $candidates = DB::table('shopping_lists')
                ->where('family_id', $settings->family_id)
                ->where('name', 'Inventory')
                ->whereNull('archived_at')
                ->pluck('id');

            if ($candidates->count() !== 1) {
                continue; // none, or ambiguous — leave the family alone
            }

            DB::table('shopping_lists')->where('id', $candidates->first())->update(['name' => 'Replenish']);
            DB::table('inventory_settings')->where('id', $settings->id)->update(['shopping_list_id' => $candidates->first()]);
        }
    }

    public function down(): void
    {
        // Not reversed: "Inventory" would collide with the new code's expectations, and the family may
        // have renamed the list by hand since. The pointer keeps resolution working under any name.
    }
};
