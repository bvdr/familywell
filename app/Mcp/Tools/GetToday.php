<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Support\Dashboard\DashboardAgenda;
use App\Support\Dashboard\DashboardItems;
use App\Support\Mcp\McpTools;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetToday extends Tool
{
    protected string $module = McpTools::CORE;

    protected string $name = 'get_today';

    protected string $description = "What's on for this person: open dated items (tasks, shopping items) due today or this week, overdue ones included, plus what they recently opened. Only covers modules this connection may read.";

    public function handle(Request $request): Response
    {
        // Same providers as the web dashboard, limited to the modules this token can read.
        $readable = array_keys(McpTools::accessFromScopes($this->tokenScopes()));
        $today = now()->toDateString();

        $agenda = collect(DashboardAgenda::collect($readable))->sortBy('dueDate')->values()
            ->map(fn ($i) => [
                'title' => $i['title'],
                'where' => $i['subtitle'],
                'due_date' => $i['dueDate'],
                'overdue' => $i['dueDate'] < $today,
                'url' => $this->url($i['href']),
            ]);

        $recent = collect(DashboardItems::collect($readable))->sortByDesc('visitedAt')->values()
            ->map(fn ($i) => ['title' => $i['title'], 'subtitle' => $i['subtitle'], 'module' => $i['category'], 'url' => $this->url($i['href'])]);

        return Response::json([
            'today' => $today,
            'due' => $agenda->filter(fn ($i) => $i['due_date'] <= $today)->values(),
            'later_this_week' => $agenda->filter(fn ($i) => $i['due_date'] > $today)->values(),
            'recently_opened' => $recent,
        ]);
    }
}
