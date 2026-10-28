<?php

namespace App\Support\Add;

use App\Models\Family;
use App\Models\User;
use App\Support\Engagement;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;

/**
 * What the reader knows besides the input (the user asked for this, see memory "add anything"): where the + was
 * pressed (the module and entity in the page's path, plus the module's own one-line description of that screen), who
 * is in the family, today's date, and this person's habits: how much the family added per module in 30 days and the
 * person's own last adds. All of it is plain text in the decision model's state, so the model weighs it, not our code.
 */
final class AddContext
{
    /** @param array<int, string> $members user id => name */
    /** $text: everything the decision model weighs; $facts: the date + who's who, all the extractor gets. */
    public function __construct(
        public readonly User $user,
        public readonly ?string $module,
        public readonly ?int $entityId,
        public readonly array $members,
        public readonly string $text,
        public readonly string $facts,
    ) {}

    public static function for(User $user, string $path): self
    {
        // /m/{module}/... and the first number after it: /m/shopping/lists/12, /m/vehicles/3, /m/spending/events/7.
        preg_match('#^/m/([a-z_-]+)(?:/[a-z-]+)?/(\d+)#', $path, $withId);
        preg_match('#^/m/([a-z_-]+)#', $path, $m);
        $module = isset($m[1]) ? str_replace('-', '_', $m[1]) : null;
        $entityId = isset($withId[2]) ? (int) $withId[2] : null;

        $family = Family::find(CurrentFamily::id());
        $members = $family ? $family->members()->pluck('users.name', 'users.id')->all() : [];
        $registry = app(ModuleRegistry::class);
        $screen = $module ? ($registry->manifest($module)?->name ?? $module) : 'Home';
        $about = AddKinds::describeScreen($module, $entityId, $user);

        // $facts is all the extraction model sees besides the input: it copies text out of whatever it is given, so the
        // screen line and the habits (other cars, past adds) would leak into fields. Those go to the decision model only.
        // The coming week spelled out: without it "thursday 4:30" came back as Friday (live test, 2026-10-28).
        $week = collect(range(1, 7))->map(fn ($d) => now()->addDays($d)->format('l').' = '.now()->addDays($d)->toDateString())->implode(', ');
        $facts = 'Today is '.now()->format('l, Y-m-d').'. Coming days: '.$week.".\n".'The person adding: '.$user->name.'. Family members: '.implode(', ', $members).'.';
        $lines = [$facts, 'They pressed + on the '.$screen.' screen'.($about ? ': '.$about : '').'.'];
        if ($habits = self::habits($user)) {
            $lines[] = $habits;
        }

        return new self($user, $module, $entityId, $members, implode("\n", $lines), $facts);
    }

    /**
     * "Added in the last 30 days: shopping 36, spending 46 … Their last adds: Bananas (shopping), …". Counts are the
     * family's (a number reveals nothing); titles only the person's own, so nothing from a private list leaks in.
     * ponytail: two small queries per tracked module; cache per user if the read ever needs to be faster.
     */
    private static function habits(User $user): ?string
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
                $class::query()->where('created_by', $user->id)->latest('id')->limit(3)->get()
                    ->each(fn ($r) => $mine->push(['at' => $r->created_at, 'text' => ($r->name ?? $r->title ?? $r->note ?? $r->store ?? '?')." ({$module})"]));
            }
        }
        arsort($counts);
        $counts = array_filter($counts);
        $out = $counts ? 'Added by the family in the last 30 days: '.collect($counts)->map(fn ($n, $m) => "{$m} {$n}")->implode(', ').'.' : '';
        $last = $mine->sortByDesc('at')->take(8)->pluck('text');

        return trim($out.($last->isNotEmpty() ? ' Their last adds: '.$last->implode(', ').'.' : '')) ?: null;
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

    /** First names, for the "which of you?" chips. */
    public function memberNames(): array
    {
        return array_values(array_map(fn ($n) => explode(' ', $n)[0], $this->members));
    }
}
