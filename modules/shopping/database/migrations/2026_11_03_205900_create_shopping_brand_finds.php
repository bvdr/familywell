<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Brands the weekly sweep (shops:find-logos) found for shop names the built-in list didn't know, shared by every
// family: `key` is the squashed name ("brutarialuiion"). status found = a logo stored on R2 at logo_path;
// none = nothing trustworthy, tried again after 90 days. Not family data: no family_id, no names of people.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopping_brand_finds', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('name');
            $table->string('status', 10); // found | none
            $table->string('slug', 60)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('source')->nullable(); // where the logo came from, for a human to check
            $table->timestamp('checked_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopping_brand_finds');
    }
};
