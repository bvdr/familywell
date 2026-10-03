<?php

namespace Modules\Spending\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Modules\Spending\SpendingTrash;

// Same restore as Settings > Recently deleted (SpendingTrash).
class Restore extends Tool
{
    protected string $module = 'spending';

    protected string $access = 'write';

    protected string $name = 'spending_restore';

    protected string $description = 'Bring an expense back from the trash.';

    public function schema(JsonSchema $schema): array
    {
        return ['expense_id' => $schema->integer()->required()];
    }

    public function handle(Request $request): Response
    {
        $id = (int) $request->validate(['expense_id' => ['required', 'integer']])['expense_id'];
        $path = SpendingTrash::restore($this->user(), 'expense', $id);
        if (! $path) {
            return Response::error('Not found in the trash (it may have been restored already or removed for good).');
        }
        SpendData::changed();

        return Response::json(['restored' => true, 'id' => $id, 'url' => $this->url($path)]);
    }
}
