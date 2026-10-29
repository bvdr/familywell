<?php

namespace App\Support\Add;

use App\Models\Family;
use App\Models\User;
use App\Support\Engagement;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Tenancy\FamilyClock;

/**
 * What the reader gets besides the input (the user asked for this, see memory "add anything"): where the + was
 * pressed (the module and entity in the page's path, plus the module's own one-line description of that screen), who is
 * in the family, today's date, and this person's habits: how much the family added per module in 30 days and the
 * person's own last adds. Plain data: how it's worded for the models is the reader's business (the worker).
 */
final class AddContext
{
    /** @param array<int, string> $members user id => name */
    public function __construct(
        public readonly User $user,
        public readonly ?string $module,
        public readonly ?int $entityId,
        public readonly array $members,
        public readonly string $screenLabel,
        public readonly ?string $screenAbout,
    ) {}

    public static function for(User $user, string $path): self
    {
        // /m/{module}/... and the thing the screen is about: /m/shopping/lists/12, /m/vehicles/3, /m/spending/events/7,
        // /m/calendar/events/5. Only right after the module or a `lists` / `events` segment: a shop's page
        // (/m/shopping/shops/3) is not shopping list 3, and the modules read entityId as their list / event / car.
        preg_match('#^/m/([a-z_-]+)(?:/(?:lists|events))?/(\d+)#', $path, $withId);
        preg_match('#^/m/([a-z_-]+)#', $path, $m);
        $module = isset($m[1]) ? str_replace('-', '_', $m[1]) : null;
        $entityId = isset($withId[2]) ? (int) $withId[2] : null;

        $family = Family::find(CurrentFamily::id());
        $members = $family ? $family->members()->pluck('users.name', 'users.id')->all() : [];
        $label = $module ? (app(ModuleRegistry::class)->manifest($module)?->name ?? $module) : 'Home';

        return new self($user, $module, $entityId, $members, $label, AddKinds::describeScreen($module, $entityId, $user));
    }

    /** The reader's `context`. */
    public function payload(): array
    {
        return [
            // The family's day (FamilyClock): the reader turns "tomorrow" into a date from it.
            'today' => FamilyClock::today(),
            'person' => $this->user->name,
            'members' => collect($this->members)->map(fn ($name, $id) => ['id' => (int) $id, 'name' => $name])->values()->all(),
            'screen' => ['module' => $this->module, 'label' => $this->screenLabel, 'about' => $this->screenAbout],
            'habits' => $this->habits(),
        ];
    }

    /**
     * Family-wide counts per module in 30 days (a number reveals nothing), and only the person's OWN last titles, so
     * nothing from someone's private list leaks in.
     * ponytail: two small queries per tracked module; cache per user if the read ever needs to be faster.
     */
    private function habits(): array
    {
        $on = app(ModuleRegistry::class)->enabledIds((int) CurrentFamily::id());
        $counts = [];
        $mine = collect();
        foreach (Engagement::tracked() as $module => $class) {
            if (! in_array($module, $on, true)) {
                continue;
            }
            $model = new $class;
            $counts[$module] = $class::query()->where($model->getCreatedAtColumn(), '>=', now()->subDays(30))->count();
            if ($model->isFillable('created_by')) {
                $class::query()->where('created_by', $this->user->id)->latest('id')->limit(3)->get()
                    ->each(fn ($r) => $mine->push(['at' => $r->created_at, 'module' => $module, 'title' => (string) ($r->name ?? $r->title ?? $r->note ?? $r->store ?? '?')]));
            }
        }

        return ['counts' => $counts, 'recent' => $mine->sortByDesc('at')->take(8)->map(fn ($r) => ['module' => $r['module'], 'title' => $r['title']])->values()->all()];
    }

    /** A member's user id from a first name or full name the reader returned ("Lucia"), or null. */
    public function memberId(?string $name): ?int
    {
        if (! $name) {
            return null;
        }
        $name = mb_strtolower(trim($name));
        foreach ($this->members as $id => $full) {
            $full = mb_strtolower($full);
            if ($full === $name || explode(' ', $full)[0] === $name) {
                return (int) $id;
            }
        }

        return null;
    }
}
