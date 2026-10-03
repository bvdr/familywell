<?php

namespace Modules\Spending\Mcp;

use App\Mcp\Tool;
use App\Support\Sharing\ShareAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Modules\Spending\Spend;

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
        $spend = Spend::onlyTrashed()->with('event')->find((int) $request->get('expense_id'));
        if (! $spend || ! $spend->event || ! app(ShareAccess::class)->canEdit($this->user(), $spend->event)) {
            return Response::error('Not found in the trash (it may have been restored already or removed for good).');
        }

        $spend->restore();
        SpendData::changed();

        return Response::json(['restored' => true] + SpendData::payload($spend->fresh(['event', 'payer', 'payerGuest'])));
    }
}
