<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Expense import (#215). Its own tables only: imported expenses are ordinary Spending rows (spending_spends),
// these just remember the review step, the batches (for undo) and which bank transactions came in already.
return new class extends Migration
{
    public function up(): void
    {
        // One row per family: what is switched on and where imported expenses go by default.
        Schema::create('expense_import_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('statements_enabled')->default(false);
            $table->boolean('shortcut_enabled')->default(false);
            // Plain ids, no FK (like spending_settings.others_event_id): a deleted list or member just falls
            // back to the "Others" list / whoever imports, it never blocks the delete.
            $table->unsignedBigInteger('default_event_id')->nullable();
            $table->string('default_payer_key', 20)->nullable(); // 'u{id}'; null = whoever adds it
            // Apple Pay payments: false = wait in the review queue, true = save straight away.
            $table->boolean('auto_save')->default(false);
            $table->timestamps();
        });

        // A person's private add-expense link for the iPhone Shortcut. Plain text (not hashed) so Settings can
        // show the link again, same as the calendar ICS feed token; revoking deletes the row.
        Schema::create('expense_import_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->unique(['family_id', 'user_id']);
        });

        // One upload (or the Apple Pay review queue) = one batch: reviewed, imported, and undone as a whole.
        Schema::create('expense_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // who imported it; only they see it
            $table->string('source', 20); // statement | shortcut
            $table->string('status', 20); // mapping | review | imported | undone
            $table->string('bank', 60)->nullable(); // detected format or the name given to a mapped one
            $table->string('filename')->nullable();
            $table->unsignedBigInteger('event_id')->nullable(); // target list chosen on review
            $table->string('payer_key', 20)->nullable();
            // An unknown statement format waits here (header + rows) until its columns are mapped once.
            $table->longText('raw')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();
            $table->index(['family_id', 'user_id', 'status']);
        });

        Schema::create('expense_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained('expense_import_batches')->cascadeOnDelete();
            $table->date('spent_at');
            $table->bigInteger('amount'); // minor units, always positive; `kind` says which way it went
            $table->string('currency', 3);
            $table->string('merchant', 190);
            $table->string('reference', 120)->nullable(); // the bank's own id, when the statement has one
            // What remembers a transaction across statements: the bank reference, else date+amount+merchant.
            $table->string('fingerprint', 64);
            $table->string('kind', 16); // expense | income | refund | transfer
            $table->string('flag', 20)->nullable(); // duplicate | imported (already came in) | currency
            $table->boolean('selected')->default(true);
            $table->string('category', 40)->nullable(); // null = not decided yet (shown and saved as "other")
            $table->boolean('ai_tried')->default(false);
            $table->unsignedBigInteger('spend_id')->nullable(); // the Spending expense it became
            $table->timestamps();
            $table->index(['family_id', 'fingerprint']);
        });

        // A bank format we don't know, mapped once by the family: header signature -> which column is what.
        Schema::create('expense_import_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();
            $table->string('signature', 64);
            $table->string('label', 60);
            $table->json('mapping');
            $table->timestamps();
            $table->unique(['family_id', 'signature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_import_mappings');
        Schema::dropIfExists('expense_import_rows');
        Schema::dropIfExists('expense_import_batches');
        Schema::dropIfExists('expense_import_tokens');
        Schema::dropIfExists('expense_import_settings');
    }
};
