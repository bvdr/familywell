<?php

namespace Modules\Spending;

// Thin accessor over the generated lucide icon-name list (lucide-icon-names.php). A stored `icon`
// key is only ever set by the IconPicker component, which offers exactly this lucide set, so
// validating a key means checking membership here. Loaded once and cached for the request.
class IconLibrary
{
    /** @var array<string,true>|null lookup map (name => true) for O(1) `has`, built lazily. */
    private static ?array $lookup = null;

    /** @return list<string> every valid lucide icon key (kebab-case). */
    public static function names(): array
    {
        return array_keys(self::lookup());
    }

    /** True when $name is a real lucide icon key (used by the store/update validation rule). */
    public static function has(?string $name): bool
    {
        return $name !== null && isset(self::lookup()[$name]);
    }

    /** @return array<string,true> */
    private static function lookup(): array
    {
        if (self::$lookup === null) {
            self::$lookup = array_fill_keys(require __DIR__.'/lucide-icon-names.php', true);
        }

        return self::$lookup;
    }
}
