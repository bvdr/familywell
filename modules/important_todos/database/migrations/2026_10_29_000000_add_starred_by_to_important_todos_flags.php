<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who starred it. Home's "Your important" card gives an important to-do to its assignee; one nobody is
        // assigned to belongs to whoever starred it (ImportantCard::OWNER). Nullable: flags from before this column
        // fall back to the task's creator. Null on delete so a person leaving doesn't take the flag along.
        Schema::table('important_todos_flags', function (Blueprint $table) {
            $table->foreignId('starred_by')->nullable()->after('important')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('important_todos_flags', function (Blueprint $table) {
            $table->dropConstrainedForeignId('starred_by');
        });
    }
};
