<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Recipes. Ingredients and steps are JSON on the recipe, not child tables: they are always read and
// saved as a whole (the editor replaces the full list, like contact methods), nothing queries one
// ingredient on its own, and the calculator scales the list in memory. Order is the array order.
//   ingredients: [{group?, name, qty?, unit?, note?, scalable}]  (qty null = "to taste"; scalable=false
//                never scales, e.g. salt, a bay leaf)
//   steps:       ["Mix the flour...", ...]
// The reference amount the calculator scales from is `servings` and/or `yield_amount` + `yield_unit`
// (e.g. 1200 g of dough). Sharing (family/private + guest link) comes from the core share tables.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes_recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('photo_path')->nullable();
            $table->unsignedSmallInteger('servings')->nullable();
            $table->decimal('yield_amount', 10, 2)->nullable();
            $table->string('yield_unit', 20)->nullable();
            $table->unsignedSmallInteger('prep_minutes')->nullable();
            $table->unsignedSmallInteger('cook_minutes')->nullable();
            $table->json('ingredients');
            $table->json('steps');
            $table->json('tags');
            $table->string('source_url', 500)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by');
            $table->timestamps();
            $table->softDeletes(); // 30-day trash, like notes and lists
            $table->index(['family_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipes_recipes');
    }
};
