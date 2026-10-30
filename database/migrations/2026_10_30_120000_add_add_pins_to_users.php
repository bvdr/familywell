<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The Classic Add sheet's pinned shortcut(s) (design H2d: "pin one to keep it on top"): a list of module ids,
        // per person like home_layout / pinned_modules, so the pin follows them to every device. Null = none pinned.
        Schema::table('users', function (Blueprint $table) {
            $table->json('add_pins')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('add_pins');
        });
    }
};
