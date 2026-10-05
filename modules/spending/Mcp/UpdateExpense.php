<?php

namespace Modules\Spending\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class UpdateExpense extends Tool
{
    protected string $module = 'spending';

    protected string $access = 'write';

    protected string $name = 'spending_update_expense';

    protected string $description = 'Change an expense. Only the fields you pass change; the amount is re-converted into the list\'s currency at the (new) date.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'expense_id' => $schema->integer()->required(),
            'amount' => $schema->number(),
            'currency' => $schema->string(),
            'title' => $schema->string()->nullable(),
            'category' => $schema->string(),
            'shop' => $schema->string()->nullable(),
            'date' => $schema->string()->format('date'),
            'paid_by' => $schema->integer(),
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
            'payer_key' => $has('paid_by') ? 'u'.$request->get('paid_by') : $spend->participantKey(),
            'source_id' => $spend->source_id,
            'note' => $has('title') ? $request->get('title') : $spend->note,
            'store' => $has('shop') ? $request->get('shop') : $spend->store,
            'spent_at' => $has('date') ? $request->get('date') : $spend->spent_at->toDateString(),
        ], $event->spendRules(), ['payer_key.in' => 'paid_by must be a member taking part in this list.']);

        $spend->update($event->spendColumns($data));
        SpendData::changed();

        return Response::structured(SpendData::payload($spend->fresh(['event', 'payer', 'payerGuest'])));
    }
}
