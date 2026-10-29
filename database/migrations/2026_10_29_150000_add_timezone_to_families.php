<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The family's IANA zone ("Europe/Bucharest"), picked in Settings › Region & units. Every "which day is it"
        // decision reads it through App\Support\Tenancy\FamilyClock. Null = not picked yet: FamilyClock falls back to
        // config('app.timezone') and the first admin visit fills it from their browser (FillFamilyTimezone).
        Schema::table('families', function (Blueprint $table) {
            $table->string('timezone', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('families', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
