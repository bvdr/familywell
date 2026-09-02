<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// A birthday no longer needs a year: the recurring part is month + day, the year (as the full
// `birthdate`) is optional and only unlocks age / "turns N". Existing rows are backfilled from the
// date they already had, so nothing changes for people entered with a full date.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('birthdays_dates', function (Blueprint $table) {
            $table->unsignedTinyInteger('month')->nullable()->after('person_id');
            $table->unsignedTinyInteger('day')->nullable()->after('month');
            $table->date('birthdate')->nullable()->change();
        });

        foreach (DB::table('birthdays_dates')->whereNotNull('birthdate')->get(['id', 'birthdate']) as $row) {
            $d = Carbon::parse($row->birthdate);
            DB::table('birthdays_dates')->where('id', $row->id)->update(['month' => $d->month, 'day' => $d->day]);
        }
    }

    public function down(): void
    {
        Schema::table('birthdays_dates', function (Blueprint $table) {
            $table->dropColumn(['month', 'day']);
        });
    }
};
