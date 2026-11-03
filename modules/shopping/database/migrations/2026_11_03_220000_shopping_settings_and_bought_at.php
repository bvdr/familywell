<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Shopping settings (design S1d): one row per family; what new lists start with, the helpers, and clean-up.
// bought_at: when an item was ticked, for auto-clean ("leave the list 7 days after they were ticked") and a shop's
// "Last trip". Existing bought items take their last update as a best guess.
// shopping_lists.clean_after_days: a list's own auto-clean (S2c), null = follow the family's setting, 0 = never.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopping_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->unique();
            $table->string('default_shop')->nullable();
            $table->string('sort', 10)->default('aisle'); // aisle | az | added
            $table->string('bought', 10)->default('sink'); // sink | hide
            $table->boolean('ai_sort')->default(true);
            $table->boolean('warn_duplicates')->default(true);
            $table->boolean('keep_awake')->default(true);
            $table->boolean('auto_clean')->default(true);
            $table->unsignedSmallInteger('clean_after_days')->default(7);
            $table->timestamps();
        });
        Schema::table('shopping_items', function (Blueprint $table) {
            $table->timestamp('bought_at')->nullable();
        });
        DB::table('shopping_items')->where('bought', true)->update(['bought_at' => DB::raw('updated_at')]);
        Schema::table('shopping_lists', function (Blueprint $table) {
            $table->unsignedSmallInteger('clean_after_days')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopping_settings');
        Schema::table('shopping_items', fn (Blueprint $t) => $t->dropColumn('bought_at'));
        Schema::table('shopping_lists', fn (Blueprint $t) => $t->dropColumn('clean_after_days'));
    }
};
