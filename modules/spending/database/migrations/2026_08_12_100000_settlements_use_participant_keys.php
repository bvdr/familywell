<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Settlements were user-only (from_user_id/to_user_id). Move them onto the same namespaced participant
// keys the rest of the module uses ('u{id}' member / 'g{id}' guest) so a transfer can involve a guest.
// Idempotent: each step is guarded on column presence so a re-run after a partial apply is a no-op.
return new class extends Migration
{
    public function up(): void
    {
        // Add the key columns (nullable while we backfill).
        if (! Schema::hasColumn('spending_settlements', 'from_key')) {
            Schema::table('spending_settlements', fn (Blueprint $t) => $t->string('from_key')->nullable()->after('spend_event_id'));
        }
        if (! Schema::hasColumn('spending_settlements', 'to_key')) {
            Schema::table('spending_settlements', fn (Blueprint $t) => $t->string('to_key')->nullable()->after('from_key'));
        }
        // Optional note shown in the Transfers list (e.g. "cash", "for the tickets").
        if (! Schema::hasColumn('spending_settlements', 'note')) {
            Schema::table('spending_settlements', fn (Blueprint $t) => $t->string('note')->nullable()->after('amount'));
        }

        // Backfill from the old user columns. Existing settlements were all member-to-member -> 'u{id}'.
        if (Schema::hasColumn('spending_settlements', 'from_user_id')) {
            // '||' string concat works on both Postgres (prod) and SQLite (tests); concat() is pg-only.
            DB::table('spending_settlements')->whereNull('from_key')->update([
                'from_key' => DB::raw("'u' || from_user_id"),
                'to_key' => DB::raw("'u' || to_user_id"),
            ]);
            Schema::table('spending_settlements', fn (Blueprint $t) => $t->dropColumn(['from_user_id', 'to_user_id']));
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('spending_settlements', 'from_user_id')) {
            Schema::table('spending_settlements', function (Blueprint $t) {
                $t->foreignId('from_user_id')->nullable()->after('spend_event_id');
                $t->foreignId('to_user_id')->nullable()->after('from_user_id');
            });
            // Best-effort: only 'u{id}' keys map back to user ids; guest rows can't and are left null.
            DB::table('spending_settlements')->where('from_key', 'like', 'u%')->update([
                'from_user_id' => DB::raw("cast(substring(from_key from 2) as integer)"),
                'to_user_id' => DB::raw("cast(substring(to_key from 2) as integer)"),
            ]);
        }
        if (Schema::hasColumn('spending_settlements', 'from_key')) {
            Schema::table('spending_settlements', fn (Blueprint $t) => $t->dropColumn(['from_key', 'to_key']));
        }
        if (Schema::hasColumn('spending_settlements', 'note')) {
            Schema::table('spending_settlements', fn (Blueprint $t) => $t->dropColumn('note'));
        }
    }
};
