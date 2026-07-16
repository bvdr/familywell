<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('families', function (Blueprint $table) {
            // Family "avatar" is a color + emoji chosen during onboarding (design: Create Family).
            // Defaults match the brand leaf so existing rows and API-created families still render.
            $table->string('description')->nullable()->after('name');
            $table->string('color', 20)->default('#2D5E3A')->after('description');
            $table->string('emoji', 16)->default('🌿')->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('families', function (Blueprint $table) {
            $table->dropColumn(['description', 'color', 'emoji']);
        });
    }
};
