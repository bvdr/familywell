<?php

namespace App\Support\Add;

use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Closure;

/**
 * What "Just say it" (the Add sheet's AI box, design XMkKm H2) can add, contributed by the modules that own each thing:
 * a module registers a kind from its service provider, so the core never knows about events or expenses. A kind is:
 *  - fields:   what the reader should fill, by type (text | number | date | datetime | list, a list may be of objects
 *              via 'of'); 'required' => true (the card can't be added without it); 'ask' => 'member' (an unsure "who"
 *              becomes a question); 'options' => Closure(User): list<string> (the only values that make sense, e.g. the
 *              person's list names); 'hidden' => true (read, but not shown as a field on the card). How to recognise
 *              and read each field is the reader's job (AddReader: the worker);
 *              'details' => true: a field of the card's "Add details" form only (design H2f), what Classic offers
 *              beyond what one sentence says (a to-do's note, its list picked by hand). The reader never sees these
 *              (AddReader strips them) so the worker's schema, eval and cost don't change; the person fills them.
 *              Detail-only types: note (a longer text under the title), choice ('choices' => Closure(User):
 *              list<array{value: string, label: string}>, a row that opens a picker) and slot ('slot' => a primitive's
 *              editor slot, e.g. task.editor.fields: the add-ons' own controls; the value is their fields, which
 *              the save hands to the primitive's saved hook exactly like Classic does);
 *  - target:   optional Closure(User, AddContext, array $values): ?string naming where it will land ("Oana"), shown on the card
 *              so it's clear what gets created where; read-only (never create anything here);
 *  - save:     Closure(User, array $values, AddContext): array{title: string, url: string} creating the thing through
 *              the module's own rules (it throws a ValidationException when the values don't hold up).
 * screen(): a module can also describe what is on its screens (Vehicles: "no vehicles yet"), so the reader knows
 * where the + was pressed. Keyed by kind / module id, so re-registration under Octane is idempotent.
 * last(): what this person added last in a module ("bananas"), the sub line under its Classic shortcut (design H2d
 * "Pinned · last: bananas"). The module owns the query because only it knows the item's name column and who may
 * see it now: an item the person added to a list that has since gone private to them must not show.
 */
final class AddKinds
{
    /** @var array<string, array{module: string, fields: array<string, array<string, mixed>>, target?: Closure, save: Closure}> */
    private static array $kinds = [];

    /** @var array<string, Closure(?int, User): ?string> */
    private static array $screens = [];

    /** @var array<string, Closure(User): ?string> */
    private static array $lasts = [];

    /** @param array{fields: array<string, array<string, mixed>>, target?: Closure, save: Closure} $def */
    public static function register(string $kind, string $module, array $def): void
    {
        self::$kinds[$kind] = ['module' => $module] + $def;
    }

    /** @param Closure(?int, User): ?string $describe  entity id from the URL (a list, a car) or null => one line */
    public static function screen(string $module, Closure $describe): void
    {
        self::$screens[$module] = $describe;
    }

    /** @param Closure(User): ?string $last  the name of the newest item $user added that they can still see, or null */
    public static function last(string $module, Closure $last): void
    {
        self::$lasts[$module] = $last;
    }

    /**
     * module id => the last thing $user added there, for the given modules (those without a last() are left out).
     * ponytail: one small indexed-by-family query per module (~9) when the sheet opens; batch if it ever shows up.
     *
     * @param  list<string>  $modules
     * @return array<string, string>
     */
    public static function lastAdded(User $user, array $modules): array
    {
        $out = [];
        foreach (array_intersect_key(self::$lasts, array_flip($modules)) as $module => $last) {
            $name = rescue(fn () => $last($user), null, false);
            if (filled($name)) {
                $out[$module] = mb_strimwidth(trim((string) $name), 0, 60, '…');
            }
        }

        return $out;
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
