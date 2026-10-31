<?php

namespace Modules\Spending\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Modules\Notifications\Notify;

#[IsDestructive]
class DeleteExpense extends Tool
{
    protected string $module = 'spending';

    protected string $access = 'write';

    protected string $name = 'spending_delete_expense';

    protected string $description = 'Use this when the user wants an expense deleted. It goes to the trash and can be restored for 30 days with spending_restore.';

    protected string $title = 'Delete expense';

    public function schema(JsonSchema $schema): array
    {
        return ['expense_id' => $schema->integer()->description('Expense id, from spending_list_expenses.')->required()];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->user();
        $spend = SpendData::spend($user, $request->get('expense_id'), edit: true);
        if (! $spend) {
            return $this->notFound('Expense');
        }

        $amount = $spend->currency.' '.number_format($spend->amount / 100, 2);
        $spend->delete();
        Notify::toAccessOf($spend->event, 'spending', $user->id, $spend->event->name, "{$user->name} deleted {$amount}", "/m/spending/events/{$spend->spend_event_id}");
        SpendData::changed();

        return Response::structured(['deleted' => true, 'id' => $spend->id] + SpendData::trashInfo($spend));
    }
}
