<?php

namespace Modules\Spending\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

class MoveExpense extends Tool
{
    protected string $module = 'spending';

    protected string $access = 'write';

    protected string $name = 'spending_move_expense';

    protected string $description = 'Move an expense to another spending list, like "Move to list" in the app. Amount, date, who paid, category, note and shop are kept; the amount is re-converted into the new list\'s currency and its split resets to everyone on the new list. An expense with no own count_in_monthly setting then follows the new list\'s. Expenses paid by a guest can\'t be moved.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'expense_id' => $schema->integer()->required(),
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

        return Response::structured(SpendData::payload($spend->fresh(['event', 'payer', 'payerGuest'])));
    }
}
