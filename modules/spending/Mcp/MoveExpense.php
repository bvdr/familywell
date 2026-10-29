<?php

namespace Modules\Spending\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class MoveExpense extends Tool
{
    protected string $module = 'spending';

    protected string $access = 'write';

    protected string $name = 'spending_move_expense';

    protected string $description = 'Use this when the user wants an expense moved to another spending list. Amount, date, payer, category, note and shop are kept; the amount is converted into the new list\'s currency and the split resets to everyone on the new list. Expenses paid by a guest can\'t be moved.';

    protected string $title = 'Move expense to list';

    public function schema(JsonSchema $schema): array
    {
        return [
            'expense_id' => $schema->integer()->description('Expense id, from spending_list_expenses.')->required(),
            'list_id' => $schema->integer()->required()->description('The spending list to move it to.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['expense_id' => ['required', 'integer'], 'list_id' => ['required', 'integer']]);
        // Same access as the web action: edit rights on both the current list and the target.
        $spend = SpendData::spend($this->user(), $data['expense_id'], edit: true);
        if (! $spend) {
            return $this->notFound('Expense');
        }
        $target = SpendData::event($this->user(), $data['list_id'], edit: true);
        if (! $target) {
            return $this->notFound('List');
        }

        try {
            $spend->moveTo($target);
        } catch (ValidationException $e) {
            return Response::error($e->validator->errors()->first());
        }
        SpendData::changed();

        return Response::structured(SpendData::payload($spend->fresh(['event', 'payer', 'payerGuest']), true));
    }
}
