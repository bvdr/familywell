<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Shopping\ShopBrands;

// The known brand a shop's name matched (Modules\Shopping\ShopBrands): its logo. Set on every save of the name;
// the existing shops are matched here once, with their family's country from its timezone.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopping_shops', function (Blueprint $table) {
            $table->string('brand', 40)->nullable();
        });
        $zones = DB::table('families')->pluck('timezone', 'id');
        foreach (DB::table('shopping_shops')->get(['id', 'family_id', 'name']) as $shop) {
            $brand = ShopBrands::match($shop->name, ShopBrands::countryOf($zones[$shop->family_id] ?? null));
            if ($brand) {
                DB::table('shopping_shops')->where('id', $shop->id)->update(['brand' => $brand]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('shopping_shops', function (Blueprint $table) {
            $table->dropColumn('brand');
        });
    }
};
