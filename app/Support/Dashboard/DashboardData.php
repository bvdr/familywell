<?php

namespace App\Support\Dashboard;

use App\Models\Family;
use App\Support\Modules\Hooks;
use App\Support\Tenancy\CurrentFamily;

// Assembles Home from the module providers:
//  - recent:   entities opened in the last few days, most-recent first ("Jump back in")
//  - today:    dated items due today or overdue ("Today")
//  - tomorrow: dated items due tomorrow ("Tomorrow")
//  - later:    dated items after tomorrow through DashboardAgenda::until() (the "Rest of week" day strip)
//  - widgets:  keyed headline data per module (e.g. widgets.spending = this month's total)
//  - summaries: the one-line status per enabled module for the "Your modules" grid
class DashboardData
{
    public static function build(): array
    {
        // The person's own section order, minus what they hid (HomeLayout). A hidden section's providers are
        // skipped below so hiding something also saves its queries.
        $sections = HomeLayout::visible(auth()->user(), CurrentFamily::id());
        $shows = fn (string $id) => in_array($id, $sections, true);

        // Recent: each provider already returns only its recently-visited entities, tagged with
        // visitedAt. Merge and sort across modules so the newest open wins regardless of module.
        $recent = ! $shows('recent') ? [] : collect(DashboardItems::collect())
            ->sortByDesc('visitedAt')
            ->take(8)
            ->values()
            ->all();

        // Rows another Home section already shows, as kind => ids (e.g. your important to-dos, pinned in their own
        // card on top), so the agenda doesn't list them twice. Modules add to it via the `dashboard.lifted` filter
        // (inert with no listener). Home-only on purpose: MCP get_today reads DashboardAgenda directly and keeps them.
        $lifted = app(Hooks::class)->filter('dashboard.lifted', []);
        $agenda = collect(DashboardAgenda::collect())
            ->reject(fn ($i) => in_array($i['id'] ?? null, $lifted[$i['kind'] ?? ''] ?? [], true))
            ->sortBy('dueDate');
        $today = now()->toDateString();
        $tomorrow = now()->addDay()->toDateString();

        // Overdue rolls into Today (it still needs doing now).
        return [
            // Home's top bar shows the family's mark + name.
            'sections' => $sections,
            'family' => Family::find(CurrentFamily::id())?->only(['name', 'color', 'symbol', 'emoji']),
            'recent' => $recent,
            'today' => $agenda->filter(fn ($i) => $i['dueDate'] <= $today)->values()->all(),
            'tomorrow' => $agenda->where('dueDate', $tomorrow)->values()->all(),
            'later' => $agenda->filter(fn ($i) => $i['dueDate'] > $tomorrow)->values()->all(),
            'widgets' => DashboardWidgets::collect(),
            // ponytail: widgets always run (one registry, three cheap providers); key them per section if one gets heavy.
            'summaries' => $shows('modules') ? DashboardSummaries::collect() : [],
        ];
    }
}
