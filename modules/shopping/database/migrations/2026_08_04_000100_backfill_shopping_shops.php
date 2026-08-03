<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Create a shop for every distinct store name a family already uses (across lists, items, item
// "also available at", and aisles), then point each aisle at its shop. Idempotent: existing shops are
// left alone and only unlinked aisles are updated, so a partial run can be re-applied safely.
return new class extends Migration
{
    public function up(): void
    {
        // Gather (family_id, name) pairs from every place a store string lives today.
        $pairs = collect();
        foreach (['shopping_lists', 'shopping_items', 'shopping_aisles'] as $table) {
            DB::table($table)->whereNotNull('store')->where('store', '!=', '')
                ->select('family_id', 'store')->distinct()->get()
                ->each(fn ($r) => $pairs->push($r->family_id.'|'.$r->store));
        }
        // Item "also available at" is a JSON array of names.
        DB::table('shopping_items')->whereNotNull('alt_stores')->select('family_id', 'alt_stores')->get()
            ->each(function ($r) use ($pairs) {
                foreach (json_decode($r->alt_stores, true) ?: [] as $alt) {
                    if (is_string($alt) && $alt !== '') {
                        $pairs->push($r->family_id.'|'.$alt);
                    }
                }
            });

        $now = now();
        foreach ($pairs->unique() as $key) {
            [$familyId, $name] = explode('|', $key, 2);
            $exists = DB::table('shopping_shops')->where('family_id', $familyId)->where('name', $name)->exists();
            if (! $exists) {
                DB::table('shopping_shops')->insert([
                    'family_id' => $familyId,
                    'name' => $name,
                    'icon' => 'store',
                    'color' => 'green',
                    'position' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // Link each aisle to its shop (matched by family + store name). Only touch unlinked rows.
        DB::table('shopping_aisles')->whereNull('shop_id')->whereNotNull('store')
            ->orderBy('id')->get()->each(function ($a) {
                $shopId = DB::table('shopping_shops')->where('family_id', $a->family_id)->where('name', $a->store)->value('id');
                if ($shopId) {
                    DB::table('shopping_aisles')->where('id', $a->id)->update(['shop_id' => $shopId]);
                }
            });
    }

    public function down(): void
    {
        // The schema migration's down() drops the tables/columns; nothing to reverse here.
    }
};
