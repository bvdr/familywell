<?php

namespace Modules\Spending\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Spending\Spend;

#[IsReadOnly]
class ListExpenses extends Tool
{
    protected string $module = 'spending';

    protected string $name = 'spending_list_expenses';

    protected string $description = 'Expenses this person can see, newest first, optionally filtered by list, date range, category, shop or who paid.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'list_id' => $schema->integer()->description('Only this spending list.'),
            'from' => $schema->string()->format('date')->description('On or after this date (YYYY-MM-DD).'),
            'to' => $schema->string()->format('date')->description('On or before this date (YYYY-MM-DD).'),
            'category' => $schema->string()->description('Category key (see spending_list_categories).'),
            'shop' => $schema->string()->description('Shop name (case-insensitive exact match).'),
            'paid_by' => $schema->integer()->description('Member id who paid (see get_family).'),
            ...$this->pageSchema($schema),
        ];
    }

    public function handle(Request $request): Response
    {
        $f = $request->validate([
            'list_id' => ['nullable', 'integer'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
            'category' => ['nullable', 'string'], 'shop' => ['nullable', 'string'], 'paid_by' => ['nullable', 'integer'],
        ]);
        $eventIds = SpendData::visibleEvents($this->user())->pluck('id');

        $query = Spend::query()->with(['event', 'payer', 'payerGuest'])
            ->whereIn('spend_event_id', $eventIds)
            ->when($f['list_id'] ?? null, fn ($q, $id) => $q->where('spend_event_id', $id))
            ->when($f['from'] ?? null, fn ($q, $d) => $q->whereDate('spent_at', '>=', $d))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->whereDate('spent_at', '<=', $d))
            ->when($f['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when($f['shop'] ?? null, fn ($q, $s) => $q->whereRaw('LOWER(store) = ?', [mb_strtolower(trim($s))]))
            ->when($f['paid_by'] ?? null, fn ($q, $id) => $q->where('payer_id', $id))
            ->orderByDesc('spent_at')->orderByDesc('id');
        $page = $this->page($query, $request);

        return Response::json([
            'expenses' => $page['items']->map(fn (Spend $s) => SpendData::payload($s))->values(),
            'next_cursor' => $page['next_cursor'],
        ]);
    }
}
