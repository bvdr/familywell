<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module previews: the module ids whose next version this person sees before everyone else (App\Support\Modules\Preview).
// Set only by `module:preview`. Null = no previews, which is everyone by default.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('preview_modules')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('preview_modules');
        });
    }
};
