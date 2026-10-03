<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// An explicit due point on a maintenance schedule: "due at 89,600 km" and/or "due on 2027-03-01".
// When set it overrides the next-due computed from last-performed + interval (MaintenanceStatus), which
// covers two cases with one rule:
//  - a ONE-OFF planned job (no interval at all, e.g. a quoted brake job): done once, then it goes to the
//    history and the schedule is removed (MaintenanceCompletion);
//  - a recurring service that has to come early this time (cleared on completion, the interval resumes).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles_maintenance_schedules', function (Blueprint $table) {
            $table->unsignedInteger('due_mileage')->nullable()->after('interval_months');
            $table->date('due_on')->nullable()->after('due_mileage');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles_maintenance_schedules', function (Blueprint $table) {
            $table->dropColumn(['due_mileage', 'due_on']);
        });
    }
};
