<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The family mark from the new onboarding: a lucide icon key ('house', 'paw-print', ...) or 'monogram' (the
// name's first letter), drawn on the family colour. Null = the older emoji mark, so existing families look
// exactly as before; the emoji column stays as that fallback.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('families', function (Blueprint $table) {
            $table->string('symbol', 40)->nullable()->after('emoji');
        });
    }

    public function down(): void
    {
        Schema::table('families', function (Blueprint $table) {
            $table->dropColumn('symbol');
        });
    }
};
