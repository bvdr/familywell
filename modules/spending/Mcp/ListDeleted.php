<?php

namespace Modules\Spending\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Spending\Spend;

#[IsReadOnly]
class ListDeleted extends Tool
{
    protected string $module = 'spending';

    protected string $access = 'write';

    protected string $name = 'spending_list_deleted';

    protected string $description = 'Expenses in the trash (from lists this person can see), newest first, with the date each will be removed for good.';

    public function schema(JsonSchema $schema): array
    {
        return $this->pageSchema($schema);
    }

    public function handle(Request $request): Response
    {
        $query = Spend::onlyTrashed()->with(['event', 'payer', 'payerGuest'])
            ->whereIn('spend_event_id', SpendData::visibleEvents($this->user())->pluck('id'))
            ->orderByDesc('deleted_at')->orderByDesc('id');
        $page = $this->page($query, $request);

        return Response::json([
            'deleted' => $page['items']->map(fn (Spend $s) => SpendData::payload($s) + SpendData::trashInfo($s))->values(),
            'next_cursor' => $page['next_cursor'],
        ]);
    }
}
