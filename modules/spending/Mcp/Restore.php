<?php

namespace Modules\Spending\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Modules\Spending\SpendingTrash;

// Same restore as Settings > Recently deleted (SpendingTrash).
class Restore extends Tool
{
    protected string $module = 'spending';

    protected string $access = 'write';

    protected string $name = 'spending_restore';

    protected string $description = 'Use this when the user wants a deleted expense back. Takes an id from spending_list_trash.';

    protected string $title = 'Restore expense';

    public function schema(JsonSchema $schema): array
    {
        return ['expense_id' => $schema->integer()->description('Expense id, from spending_list_trash.')->required()];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $id = (int) $request->validate(['expense_id' => ['required', 'integer']])['expense_id'];
        $path = SpendingTrash::restore($this->user(), 'expense', $id);
        if (! $path) {
            return Response::error('Not found in the trash (it may have been restored already or removed for good).');
        }
        SpendData::changed();

        return Response::structured(['restored' => true, 'id' => $id, 'url' => $this->url($path)]);
    }
}
