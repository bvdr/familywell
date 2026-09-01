<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// V1.1 Phase 1: SERVICE PROVIDERS / GARAGES — a lightweight, family-scoped entity (name + contact + notes)
// that maintenance history and repairs can reference instead of a free-text garage name. Naming stays under
// the module's `vehicles_` table prefix for consistency with the rest of the schema.
//
// The existing free-text `provider` columns on vehicles_maintenance_history and vehicles_repairs are KEPT as
// a fallback: a quick entry (or any pre-V1.1 row) still renders its garage name, while new/edited rows can
// point at a real provider via service_provider_id. Deleting a provider nulls the reference (nullOnDelete)
// so history is never lost — the row just falls back to whatever free text it had.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles_service_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('family_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('website')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['family_id', 'name']);
        });

        Schema::table('vehicles_maintenance_history', function (Blueprint $table) {
            // Optional link to a service provider. Coexists with the free-text `provider` (fallback); the
            // model's providerName() prefers the linked provider's name when set.
            $table->foreignId('service_provider_id')->nullable()->after('provider')
                ->constrained('vehicles_service_providers')->nullOnDelete();
        });

        Schema::table('vehicles_repairs', function (Blueprint $table) {
            $table->foreignId('service_provider_id')->nullable()->after('provider')
                ->constrained('vehicles_service_providers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles_repairs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_provider_id');
        });
        Schema::table('vehicles_maintenance_history', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_provider_id');
        });
        Schema::dropIfExists('vehicles_service_providers');
    }
};
