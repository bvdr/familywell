<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The `hello` sample module was deleted (modules/hello). Families that had it on still carry a
// family_modules row, which would keep "hello" in their enabled ids (get_family, MCP consent) with no
// module behind it. Remove every trace: the per-family rows, the install record, its table and its
// migration record. Data-only cleanup, safe to re-run.
return new class extends Migration
{
    public function up(): void
    {
        DB::table('family_modules')->where('module_id', 'hello')->delete();
        DB::table('modules')->where('module_id', 'hello')->delete();
        Schema::dropIfExists('hello_pings');
        DB::table('migrations')->where('migration', '2026_07_15_100001_create_hello_pings_table')->delete();
    }

    // ponytail: no way back; restoring the module means restoring modules/hello from git history.
    public function down(): void {}
};
