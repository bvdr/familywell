<?php

namespace App\Support\Add;

use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Closure;

/**
 * What "Just say it" (the Add sheet's AI box, design XMkKm H2) can add, contributed by the modules that own each thing:
 * a module registers a kind from its service provider, so the core never knows about events or expenses. A kind is:
 *  - question: a yes/no the decision model answers about the input ("is there an event to put on the calendar?");
 *  - fields:   what to read out of it, as typed fields (text | number | date | datetime | list), each with plain
 *              instructions; 'ask' => 'member' turns an unsure value into a "which of you?" question;
 *  - save:     Closure(User, array $values, AddContext): array{title: string, url: string} creating the thing through
 *              the module's own rules (it throws a ValidationException when the values don't hold up).
 * screen(): a module can also describe what is on its screens (Vehicles: "no vehicles yet"), so the reader knows
 * where the + was pressed. Keyed by kind / module id, so re-registration under Octane is idempotent.
 */
final class AddKinds
{
    /** @var array<string, array{module: string, question: string, fields: array<string, array<string, mixed>>, save: Closure}> */
    private static array $kinds = [];

    /** @var array<string, Closure(?int, User): ?string> */
    private static array $screens = [];

    /** @param array{question: string, fields: array<string, array<string, mixed>>, save: Closure} $def */
    public static function register(string $kind, string $module, array $def): void
    {
        self::$kinds[$kind] = ['module' => $module] + $def;
    }

    /** @param Closure(?int, User): ?string $describe  entity id from the URL (a list, a car) or null => one line */
    public static function screen(string $module, Closure $describe): void
    {
        self::$screens[$module] = $describe;
    }

    /** The kinds whose module is on for the current family. */
    public static function enabled(): array
    {
        $on = app(ModuleRegistry::class)->enabledIds((int) CurrentFamily::id());

        return array_filter(self::$kinds, fn ($k) => in_array($k['module'], $on, true));
    }

    public static function get(string $kind): ?array
    {
        return self::enabled()[$kind] ?? null;
    }

    public static function describeScreen(?string $module, ?int $entityId, User $user): ?string
    {
        $describe = $module ? (self::$screens[$module] ?? null) : null;

        return $describe ? rescue(fn () => $describe($entityId, $user), null) : null;
    }
}
