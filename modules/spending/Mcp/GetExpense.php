<?php

namespace Modules\Spending\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetExpense extends Tool
{
    protected string $module = 'spending';

    protected string $name = 'spending_get_expense';

    protected string $description = 'Use this when the user asks about one expense in detail. Returns its amount, category, shop, date, who paid and its list. Read-only.';

    protected string $title = 'Get expense';

    public function schema(JsonSchema $schema): array
    {
        return ['expense_id' => $schema->integer()->description('Expense id, from spending_list_expenses.')->required()];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $spend = SpendData::spend($this->user(), $request->get('expense_id'));

        return $spend ? Response::structured(SpendData::payload($spend, true)) : $this->notFound('Expense');
    }
}
