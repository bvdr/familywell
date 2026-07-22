<?php

namespace App\Support\Guests;

use Illuminate\Database\Eloquent\Model;

// Whitelist of guest-shareable entity types. A slug ("spend_event") maps to a model class so the
// guest-link endpoints can resolve `{type}/{id}` without ever instantiating an arbitrary class from
// a request. Modules register their types from their service provider boot() (idempotent).
class GuestableRegistry
{
    /** @var array<string, class-string<Model>> */
    private static array $types = [];

    public static function register(string $slug, string $modelClass): void
    {
        self::$types[$slug] = $modelClass;
    }

    public static function slugFor(Model $entity): ?string
    {
        return array_search($entity::class, self::$types, true) ?: null;
    }

    // Resolve a registered entity, or 404. Callers still authorize (owner-only) separately.
    public static function resolve(string $slug, int|string $id): Model
    {
        $class = self::$types[$slug] ?? null;
        abort_unless($class !== null, 404, "Unknown guestable type [{$slug}]");

        return $class::findOrFail($id);
    }
}
