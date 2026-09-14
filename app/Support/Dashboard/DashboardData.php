<?php

namespace App\Support\Dashboard;

// Assembles the three dashboard lists from the module providers:
//  - recent:   entities opened in the last few days, most-recent first ("Pick up where you left off")
//  - today:    dated items due today or overdue ("Today")
//  - thisWeek: dated items due later this week ("This Week")
//  - widgets:  keyed headline data per module (e.g. widgets.spending = this month's total)
class DashboardData
{
    public static function build(): array
    {
        // Recent: each provider already returns only its recently-visited entities, tagged with
        // visitedAt. Merge and sort across modules so the newest open wins regardless of module.
        $recent = collect(DashboardItems::collect())
            ->sortByDesc('visitedAt')
            ->take(8)
            ->values()
            ->all();

        $agenda = collect(DashboardAgenda::collect());
        $today = now()->toDateString();
        $weekEnd = now()->endOfWeek()->toDateString();

        // Overdue rolls into Today (it still needs doing now). This Week is everything due after
        // today through the end of the week.
        $todayItems = $agenda
            ->filter(fn ($i) => $i['dueDate'] <= $today)
            ->sortBy('dueDate')->values()->all();
        $weekItems = $agenda
            ->filter(fn ($i) => $i['dueDate'] > $today && $i['dueDate'] <= $weekEnd)
            ->sortBy('dueDate')->values()->all();

        return ['recent' => $recent, 'today' => $todayItems, 'thisWeek' => $weekItems, 'widgets' => DashboardWidgets::collect()];
    }
}
