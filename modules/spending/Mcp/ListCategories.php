<?php

namespace Modules\Spending\Mcp;

use App\Mcp\Tool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Spending\SpendCategory;
use Modules\Spending\SpendingController;
use Modules\Spending\SpendSetting;

#[IsReadOnly]
class ListCategories extends Tool
{
    protected string $module = 'spending';

    protected string $name = 'spending_list_categories';

    protected string $description = 'The family\'s expense categories (key + name), the supported currencies, and the spending lists this person can add expenses to.';

    public function handle(Request $request): Response|ResponseFactory
    {
        return Response::structured([
            'categories' => SpendCategory::forFamily()->map(fn (SpendCategory $c) => ['key' => $c->key, 'name' => $c->name])->values(),
            'currencies' => SpendingController::CURRENCIES,
            'default_currency' => SpendSetting::defaultCurrency(),
            'lists' => SpendData::visibleEvents($this->user())->whereNull('archived_at')->orderBy('name')->get(['id', 'name', 'currency'])
                ->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'currency' => $e->currency])->values(),
        ]);
    }
}
