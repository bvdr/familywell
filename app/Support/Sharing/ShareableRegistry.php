<?php

namespace App\Support\Sharing;

use Illuminate\Database\Eloquent\Model;

// Whitelist of shareable entity types. A slug ("spend_event") maps to a model class so the share
// endpoints can resolve `{type}/{id}` from the URL without ever instantiating an arbitrary class
// (never trust a class name from a request). Modules register their types from their service
// provider boot(), which re-runs each request; registration is idempotent.
class ShareableRegistry
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

    // Resolve a registered entity, or abort 404. Callers still authorize access separately.
    public static function resolve(string $slug, int|string $id): Model
    {
        $class = self::$types[$slug] ?? null;
        abort_unless($class !== null, 404, "Unknown shareable type [{$slug}]");

        return $class::findOrFail($id);
    }
}
