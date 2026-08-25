<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Skin selection: a family sets a default skin; a member can override it for themselves. Both nullable
// -> null means "inherit" (user falls back to family, family falls back to the built-in default).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('theme', 40)->nullable()->after('locale');
        });
        Schema::table('families', function (Blueprint $table) {
            $table->string('theme', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('theme'));
        Schema::table('families', fn (Blueprint $table) => $table->dropColumn('theme'));
    }
};
