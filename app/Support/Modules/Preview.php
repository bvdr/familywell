<?php

namespace App\Support\Modules;

use App\Models\User;

/**
 * Module previews: ship a module's next version to chosen people first, try it on prod, then release it.
 *
 * - A change inside a module: bump its module.json version and wrap the new behaviour in Preview::on('calendar')
 *   (PHP) or usePreview('calendar') (React, lib/preview.ts). Opted-in people get the new path, everyone else the
 *   old one. Releasing = delete the checks and the old path.
 * - A whole new module: "preview": true in its module.json hides it (ModuleRegistry::enabledIds) from everyone
 *   who isn't opted in. Releasing = remove the key.
 *
 * Who is in: users.preview_modules, set with `php artisan module:preview <module> <email> [--off]`.
 */
class Preview
{
    public static function on(string $moduleId, ?User $user = null): bool
    {
        $user ??= auth()->user();

        return $user instanceof User && in_array($moduleId, $user->preview_modules ?? [], true);
    }
}
