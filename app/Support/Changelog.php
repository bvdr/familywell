<?php

namespace App\Support;

use App\Models\User;

/**
 * What's new (design ntvzN, section C): resources/changelog.json, newest first. Every user-facing change ships with
 * an entry there in the same PR: {version, date, items: [{module, title, text, href}], fixes: [..]}. Up to three items
 * a release; a fixes-only release has no items. English only for now, like the commit log it comes from.
 */
class Changelog
{
    /** @return list<array{version: string, date: string, items: list<array<string, ?string>>, fixes: list<string>}> */
    public static function all(): array
    {
        static $all;

        return $all ??= json_decode(file_get_contents(resource_path('changelog.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function latest(): ?string
    {
        return self::all()[0]['version'] ?? null;
    }

    /** The Me row's dot: a release this person hasn't opened What's new on yet. */
    public static function unseen(User $user): bool
    {
        $latest = self::latest();

        return $latest !== null && $user->seen_version !== $latest;
    }
}
