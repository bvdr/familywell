<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The public demo family is flagged here, and ONLY here. This flag is the safety anchor for the nightly
// `demo:reset`: the reset locates the family to wipe by `is_demo = true` and refuses to run unless exactly
// one row carries it (see App\Support\Demo\DemoFamily). It is deliberately not mass-assignable on the
// Family model, so no request can ever flip it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('families', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('families', fn (Blueprint $table) => $table->dropColumn('is_demo'));
    }
};
