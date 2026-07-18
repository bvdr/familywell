<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Move receipts from one-per-spend (spending_spends.receipt_path) to many-per-spend. Expand-contract:
// we add the table and copy existing photos across, but leave the old column in place so no data is
// destroyed on deploy. A later migration can drop spending_spends.receipt_path once this is proven.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spending_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spend_id')->constrained('spending_spends')->cascadeOnDelete();
            $table->string('path'); // R2 object key, family-namespaced
            $table->timestamps();
        });

        // Backfill: one receipt row per existing single receipt. Idempotent-ish; only runs while the
        // old column still exists and only for spends that don't already have a receipt row.
        if (Schema::hasColumn('spending_spends', 'receipt_path')) {
            DB::table('spending_spends')
                ->whereNotNull('receipt_path')
                ->orderBy('id')
                ->each(function ($spend) {
                    $exists = DB::table('spending_receipts')->where('spend_id', $spend->id)->exists();
                    if (! $exists) {
                        DB::table('spending_receipts')->insert([
                            'spend_id' => $spend->id,
                            'path' => $spend->receipt_path,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('spending_receipts');
    }
};
