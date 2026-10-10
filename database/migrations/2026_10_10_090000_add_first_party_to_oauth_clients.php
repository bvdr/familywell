<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Marks first-party clients (the module-requests board and its admin) on the client row itself. This
// replaces the BOARD_CLIENT_IDS env list, which drifted from production (the var was never set there,
// so the board client was treated as a normal MCP client and got the module consent screen).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->boolean('first_party')->default(false)->after('revoked');
        });
    }

    public function down(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->dropColumn('first_party');
        });
    }

    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
