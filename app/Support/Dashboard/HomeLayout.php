<?php

namespace App\Support\Dashboard;

use App\Models\User;
use App\Support\Modules\ModuleRegistry;

// Each person's own arrangement of Home (Settings > Home dashboard, design M6b): the order of its sections and
// which are hidden, stored as users.home_layout = {order: [ids], hidden: [ids]} (null = default: this order, all on).
// Only the sections Home really has; the ids match what dashboard.tsx renders.
class HomeLayout
{
    /** Section id => the module it needs on (null = always there). In the default order. */
    public const SECTIONS = [
        'birthdays' => 'birthdays',
        'today' => null,     // Today + Tomorrow
        'week' => null,      // Rest of the week strip
        'anytime' => 'tasks', // To-dos with no date
        'money' => 'spending',
        'modules' => null,   // Your modules / Pinned grid
        'recent' => null,    // Jump back in
    ];

    /**
     * Every section this family can have, in the person's order (saved ids first, then any they never placed, in
     * the default order), each with its on/off. A section whose module is off is left out entirely: it stays hidden
     * whatever the person saved, and comes back in its saved place once the module is on again.
     *
     * @return list<array{id: string, on: bool}>
     */
    public static function for(User $user, ?int $familyId): array
    {
        $enabled = $familyId ? app(ModuleRegistry::class)->enabledIds($familyId) : [];
        $available = array_keys(array_filter(self::SECTIONS, fn ($module) => $module === null || in_array($module, $enabled, true)));
        $saved = $user->home_layout ?? [];
        $order = array_values(array_unique([...array_intersect($saved['order'] ?? [], $available), ...$available]));
        $hidden = $saved['hidden'] ?? [];

        return array_map(fn ($id) => ['id' => $id, 'on' => ! in_array($id, $hidden, true)], $order);
    }

    /** @return list<string> the ids Home renders, in order. */
    public static function visible(User $user, ?int $familyId): array
    {
        return array_values(array_column(array_filter(self::for($user, $familyId), fn ($s) => $s['on']), 'id'));
    }
}
