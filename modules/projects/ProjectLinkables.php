<?php

namespace Modules\Projects;

use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Support\Collection;
use Modules\Contacts\ContactMethod;
use Modules\Contacts\Person;
use Modules\Spending\Fx;
use Modules\Spending\SpendEvent;

/**
 * Spending lists (`spend_event`) and contacts (`contact`) linked to a project through the same
 * projects_links rows as shopping lists and notes. One place for the rules, shared by the web page
 * (ProjectsController) and the MCP tools, so both see and link exactly the same things:
 *  - a type is only linkable while its module is enabled for the family;
 *  - a spending list must be visible to the viewer (family visibility, or shared with them), the same
 *    filter the spending overview uses; contacts are family-wide (BelongsToFamily is the boundary);
 *  - unlinking only removes the link row, never the list or the person.
 */
class ProjectLinkables
{
    public const SPEND = 'spend_event';

    public const CONTACT = 'contact';

    /** link type => owning module id */
    public const MODULES = [self::SPEND => 'spending', self::CONTACT => 'contacts'];

    public static function enabled(string $type): bool
    {
        return app(ModuleRegistry::class)->isEnabled(self::MODULES[$type], (int) CurrentFamily::id());
    }

    /** Linked items of a type as payloads, or null when the type's module is off (the UI hides it). */
    public static function linked(Project $project, User $user, string $type): ?Collection
    {
        if (! self::enabled($type)) {
            return null;
        }
        $ids = $project->links()->where('linkable_type', $type)->pluck('linkable_id')->all();

        return self::query($user, $type, $ids)->map(fn ($m) => self::payload($m))->values();
    }

    /** Linkable candidates (visible to the user, not yet linked) for the picker. */
    public static function candidates(Project $project, User $user, string $type): Collection
    {
        $linked = $project->links()->where('linkable_type', $type)->pluck('linkable_id')->all();

        return self::query($user, $type, null)->reject(fn ($m) => in_array($m->id, $linked))
            ->map(fn ($m) => self::payload($m))->values();
    }

    /** Links one item. False when it isn't visible to the user (or doesn't exist / module off). */
    public static function link(Project $project, User $user, string $type, int $id): bool
    {
        if (! self::enabled($type) || self::query($user, $type, [$id])->isEmpty()) {
            return false;
        }
        $project->links()->firstOrCreate(['linkable_type' => $type, 'linkable_id' => $id]);

        return true;
    }

    public static function unlink(Project $project, string $type, int $id): void
    {
        $project->links()->where(['linkable_type' => $type, 'linkable_id' => $id])->delete();
    }

    /** @param  list<int>|null  $ids  null = every visible item */
    private static function query(User $user, string $type, ?array $ids): Collection
    {
        if ($type === self::CONTACT) {
            return Person::query()->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
                ->with(['contactMethods' => fn ($q) => $q->where('type', 'phone')])
                ->orderBy('name')->get();
        }

        // Same visibility filter as SpendingController::index (family-visible, or shared with the user).
        // withSum base_amount: each spend already converted to the list's base currency.
        return SpendEvent::query()->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->withSum('spends', 'base_amount')->with(['shareSetting', 'shares'])->latest()->get()
            ->filter(fn (SpendEvent $e) => $e->visibility() === 'family' || $e->shares->contains('user_id', $user->id))
            ->values();
    }

    private static function payload(SpendEvent|Person $m): array
    {
        if ($m instanceof Person) {
            /** @var ContactMethod|null $phone */
            $phone = $m->contactMethods->first(); // only phone methods are loaded, in position order

            return ['id' => $m->id, 'name' => $m->name, 'phone' => $phone?->value];
        }

        // Total in the list's display currency, exactly like the spending overview card.
        $display = $m->display_currency ?: $m->currency;

        return [
            'id' => $m->id,
            'name' => $m->name,
            'icon' => $m->icon,
            'currency' => $display,
            'total' => Fx::convert((int) $m->spends_sum_base_amount, $m->currency, $display),
            'archived' => $m->archived_at !== null,
        ];
    }
}
