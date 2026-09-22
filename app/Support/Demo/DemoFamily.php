<?php

namespace App\Support\Demo;

use App\Models\Family;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * The public demo family: how it is found, and how its data is wiped.
 *
 * SAFETY MODEL. The only thing that makes a family "the demo" is `families.is_demo = true`. Nothing here
 * ever guesses from a name, an email or an id: locate() reads the flag, refuses when more than one family
 * carries it, and wipe() re-checks the flag on the instance it was handed and scopes EVERY delete to that
 * one family_id. A non-demo family cannot be touched by this class, by construction.
 */
class DemoFamily
{
    /**
     * The flagged family, or null when none is flagged. Throws when the flag is ambiguous (2+ rows): the
     * callers (seeder, reset command, /demo route) must never pick one.
     */
    public static function locate(): ?Family
    {
        $flagged = Family::where('is_demo', true)->get();

        if ($flagged->count() > 1) {
            throw new RuntimeException(
                "Expected at most one demo family (is_demo = true), found {$flagged->count()} (ids ".$flagged->pluck('id')->implode(', ').'). Refusing to guess.'
            );
        }

        return $flagged->first();
    }

    /**
     * Delete everything the family owns across every module, leaving the family row (and its flag)
     * in place. Thorough by construction rather than by list: every table with a `family_id` column is
     * cleared for this id, then the few child tables that hang off those rows without their own
     * family_id (spend participants/shares/receipts, event members) and the polymorphic sharing/guest
     * rows pointing at this family's entities. Users are NOT deleted here (see DemoSeeder, which
     * re-points the members).
     */
    public static function wipe(Family $family): void
    {
        // Belt and braces: even a caller bug can't route a real family through here.
        if (! $family->is_demo || ! Family::whereKey($family->id)->where('is_demo', true)->exists()) {
            throw new RuntimeException("Refusing to wipe family {$family->id}: it is not flagged is_demo.");
        }
        $id = $family->id;

        // 1. Polymorphic rows first — they need the entity ids that step 3 deletes.
        foreach (['shares', 'share_links', 'share_settings'] as $table) {
            self::wipePolymorphic($table, 'shareable', $id);
        }
        foreach (['guests', 'guest_links'] as $table) {
            self::wipePolymorphic($table, 'guestable', $id);
        }

        // 2. Children without a family_id of their own, reached through their family-scoped parent.
        $events = DB::table('spending_events')->where('family_id', $id)->select('id');
        $spends = DB::table('spending_spends')->where('family_id', $id)->select('id');
        DB::table('spending_participants')->whereIn('spend_event_id', $events)->delete();
        DB::table('spending_event_user')->whereIn('spend_event_id', $events)->delete();
        DB::table('spending_spend_shares')->whereIn('spend_id', $spends)->delete();
        DB::table('spending_receipts')->whereIn('spend_id', $spends)->delete();

        // 3. Every family-scoped table (modules included, present and future). family_user and
        // family_modules are among them: members are re-attached and modules re-enabled by the seeder.
        foreach (self::familyScopedTables() as $table) {
            DB::table($table)->where('family_id', $id)->delete();
        }
    }

    /** @return list<string> every table (except `families` itself) that has a family_id column */
    public static function familyScopedTables(): array
    {
        $tables = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if ($table !== 'families' && in_array('family_id', Schema::getColumnListing($table), true)) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    /**
     * Delete rows of a polymorphic table whose target is an entity of this family. The stored type is a
     * model class written by our own code (or a morph alias); it is only ever used to find the target
     * table, and only tables carrying a family_id qualify.
     */
    private static function wipePolymorphic(string $table, string $morph, int $familyId): void
    {
        foreach (DB::table($table)->distinct()->pluck("{$morph}_type") as $type) {
            $class = Relation::getMorphedModel($type) ?? $type;
            if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }
            $target = (new $class)->getTable();
            if (! in_array('family_id', Schema::getColumnListing($target), true)) {
                continue;
            }
            DB::table($table)
                ->where("{$morph}_type", $type)
                ->whereIn("{$morph}_id", DB::table($target)->where('family_id', $familyId)->select('id'))
                ->delete();
        }
    }
}
