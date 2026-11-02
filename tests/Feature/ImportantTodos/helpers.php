<?php

// Helpers shared by several test files. In their own file (require_once) so a parallel run (pest --parallel),
// where a worker may never load the file that used to hold them, still finds them.

use App\Models\User;
use App\Support\Dashboard\DashboardData;

use function Pest\Laravel\actingAs;

function card(User $as): ?array
{
    actingAs($as);
    request()->attributes->remove('important_todos.card'); // per-request memo: a fresh "request" per call

    return DashboardData::build()['widgets']['important_todos'] ?? null;
}
