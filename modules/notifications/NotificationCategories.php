<?php

namespace Modules\Notifications;

use App\Support\Modules\ModuleRegistry;

// Registry of notification categories. Each feature module registers its own category from its
// service provider boot() (e.g. the tasks module registers 'tasks'), so the notifications module
// never hardcodes the list. Users toggle push per category in Settings; the label + icon drive the
// settings UI and the stacked-notification title. Registration is idempotent (boot re-runs per request).
class NotificationCategories
{
    /** @var array<string, array{label: string, icon: string}> */
    private static array $categories = [];

    public static function register(string $id, string $label, string $icon = 'bell'): void
    {
        self::$categories[$id] = ['label' => $label, 'icon' => $icon];
    }

    /** @return array<string, array{label: string, icon: string}> */
    public static function all(): array
    {
        return self::$categories;
    }

    /**
     * The categories a family can see: one named after a module only while the family has that module on
     * (design M6: "only modules your family uses"); the rest ("Shared with me") always. Shared by Settings >
     * Notifications and the Me hub's "8 of 9 on", so the two never count differently.
     *
     * @return array<string, array{label: string, icon: string}>
     */
    public static function forFamily(?int $familyId): array
    {
        $registry = app(ModuleRegistry::class);
        $modules = array_map(fn ($m) => $m->id, $registry->discover());
        $enabled = $familyId ? $registry->enabledIds($familyId) : [];

        return array_filter(self::$categories, fn ($id) => ! in_array($id, $modules, true) || in_array($id, $enabled, true), ARRAY_FILTER_USE_KEY);
    }

    public static function label(string $id): string
    {
        return self::$categories[$id]['label'] ?? 'FamilyWell';
    }

    /** @return array<int, string> */
    public static function ids(): array
    {
        return array_keys(self::$categories);
    }
}
