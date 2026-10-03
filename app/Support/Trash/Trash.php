<?php

namespace App\Support\Trash;

// Reversible delete. Models that use SoftDeletes register here from their module provider so the daily
// `trash:purge` command (App\Console\Commands\PurgeTrash) knows what to force-delete once it has sat in
// the trash longer than config('familywell.trash_days'). Keyed by class, so Octane re-registration is a
// no-op. Children trashed with their parent are listed too; whichever purges first, the rest follow
// (force-deleting a parent fires the DB cascades exactly as a hard delete always did).
class Trash
{
    /** @var array<class-string, true> */
    private static array $models = [];

    /** @param  class-string  $model */
    public static function register(string $model): void
    {
        self::$models[$model] = true;
    }

    /** @return list<class-string> */
    public static function models(): array
    {
        return array_keys(self::$models);
    }

    public static function days(): int
    {
        return (int) config('familywell.trash_days', 30);
    }
}
