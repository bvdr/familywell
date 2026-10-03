<?php

namespace Modules\Spending\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Spending\Fx;
use Modules\Spending\MonthToDate;
use Modules\Spending\Spend;

#[IsReadOnly]
class Summary extends Tool
{
    protected string $module = 'spending';

    protected string $name = 'spending_summary';

    protected ?string $app = 'spending';

    protected string $description = 'Total spent over a period (default: this month so far) across the lists this person can see, in one display currency, broken down by category and by shop.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->format('date')->description('Start date, YYYY-MM-DD (default: first of this month).'),
            'to' => $schema->string()->format('date')->description('End date, YYYY-MM-DD (default: today).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $f = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = $f['from'] ?? now()->startOfMonth()->toDateString();
        $to = $f['to'] ?? now()->toDateString();

        // Same conventions as the app's "this month" figure (MonthToDate): visible, non-archived lists,
        // each list's frozen base amounts, rolled into the family's display currency at today's rate.
        $eventCcy = MonthToDate::visibleEventCurrencies($this->user()->id);
        $currency = MonthToDate::displayCurrencyFor($eventCcy);

        $rows = $eventCcy->isEmpty() ? collect() : Spend::query()
            ->whereIn('spend_event_id', $eventCcy->keys())
            ->whereDate('spent_at', '>=', $from)->whereDate('spent_at', '<=', $to)
            ->groupBy('spend_event_id', 'category', 'store')
            ->selectRaw('spend_event_id, category, store, SUM(base_amount) AS total, COUNT(*) AS n')
            ->get();

        $total = 0;
        $byCategory = [];
        $byShop = [];
        $count = 0;
        foreach ($rows as $r) {
            $cents = (int) round((int) $r->total * Fx::rate($eventCcy[$r->spend_event_id], $currency));
            $total += $cents;
            $count += (int) $r->n;
            $byCategory[$r->category] = ($byCategory[$r->category] ?? 0) + $cents;
            $shop = $r->store ?? '(no shop)';
            $byShop[$shop] = ($byShop[$shop] ?? 0) + $cents;
        }
        arsort($byCategory);
        arsort($byShop);
        $fmt = fn (array $m) => collect($m)->map(fn ($c, $k) => ['name' => (string) $k, 'amount' => SpendData::money($c)])->values();

        return Response::json([
            'from' => $from,
            'to' => $to,
            'currency' => $currency,
            'total' => SpendData::money($total),
            'expense_count' => $count,
            'by_category' => $fmt($byCategory),
            'by_shop' => $fmt($byShop),
        ]);
    }
}
