<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How a person arranged their own Home (Settings > Home dashboard): {order: [section ids], hidden: [ids]}.
        // Per person, not per family: everyone in the family sets up their own. Null = never touched, which keeps
        // the default order with every section shown (App\Support\Dashboard\HomeLayout).
        Schema::table('users', function (Blueprint $table) {
            $table->json('home_layout')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('home_layout');
        });
    }
};
