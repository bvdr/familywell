<?php

namespace Modules\Spending\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[IsDestructive]
class UpdateExpense extends Tool
{
    protected string $module = 'spending';

    protected string $access = 'write';

    protected string $name = 'spending_update_expense';

    protected string $description = 'Use this when the user wants to correct an expense. Only the fields you pass change, including count_in_monthly (whether it counts in monthly spending). The amount is converted into the list\'s currency at the (new) date.';

    protected string $title = 'Update expense';

    public function schema(JsonSchema $schema): array
    {
        return [
            'expense_id' => $schema->integer()->description('Expense id, from spending_list_expenses.')->required(),
            'amount' => $schema->number()->description('Amount in major units, e.g. 12.50.'),
            'currency' => $schema->string()->description('EUR, RON, USD or GBP.'),
            'title' => $schema->string()->description('What it was; null clears it.')->nullable(),
            'category' => $schema->string()->description('Category key, from spending_list_categories.'),
            'shop' => $schema->string()->description('Shop or merchant name; null clears it.')->nullable(),
            'date' => $schema->string()->description('YYYY-MM-DD.')->format('date'),
            'paid_by' => $schema->integer()->description('Member id who paid.'),
            'payer' => $schema->string()->description('Who paid, as a participant key of the list: "u7" (member) or "g12" (guest). Overrides paid_by.'),
            'count_in_monthly' => $schema->boolean()->nullable()->description('true/false pins whether it counts in monthly spending; null makes it follow the list\'s setting again.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $spend = SpendData::spend($this->user(), $request->get('expense_id'), edit: true);
        if (! $spend) {
            return $this->notFound('Expense');
        }
        $event = $spend->event;
        $has = fn (string $k) => array_key_exists($k, $request->all());

        // Start from the stored values and overlay what was passed, then validate the whole thing with the
        // same rules as the web form so a partial update can never leave an invalid expense behind.
        $data = Validator::validate([
            'amount' => $has('amount') ? $request->get('amount') : $spend->amount / 100,
            'currency' => $has('currency') ? $request->get('currency') : $spend->currency,
            'category' => $has('category') ? $request->get('category') : $spend->category,
            'payer_key' => $has('payer') ? $request->get('payer') : ($has('paid_by') ? 'u'.$request->get('paid_by') : $spend->participantKey()),
            'source_id' => $spend->source_id,
            'note' => $has('title') ? $request->get('title') : $spend->note,
            'store' => $has('shop') ? $request->get('shop') : $spend->store,
            'spent_at' => $has('date') ? $request->get('date') : $spend->spent_at->toDateString(),
            // Only touched when passed (absent keeps the current override; null = follow the list again).
            ...($has('count_in_monthly') ? ['count_in_monthly' => $request->get('count_in_monthly')] : []),
        ], $event->spendRules(), ['payer_key.in' => 'paid_by / payer must be one of this list\'s participants: '.implode(', ', array_keys($event->participantNames())).'.']);

        $spend->update($event->spendColumns($data));
        SpendData::changed();

        return Response::structured(SpendData::payload($spend->fresh(['event', 'payer', 'payerGuest']), true));
    }
}
