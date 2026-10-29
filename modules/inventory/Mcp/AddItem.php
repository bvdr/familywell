<?php

namespace Modules\Inventory\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Modules\Inventory\InventoryItem;

class AddItem extends Tool
{
    protected string $module = 'inventory';

    protected string $access = 'write';

    protected string $name = 'inventory_add_item';

    protected string $description = 'Use this when the user wants to start tracking a household stock item (pantry, cleaning, bathroom...). Only name and unit are required; set min_quantity to get a low-stock warning.';

    protected string $title = 'Add stock item';

    public function schema(JsonSchema $schema): array
    {
        $fields = InventoryData::fieldSchema($schema);
        $fields['name']->required();
        $fields['unit']->required();

        return $fields;
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        // Same rules + same create path (opening "added" log row, category/location/shop resolution) as
        // the app's Add Item form.
        $data = validator(InventoryData::fieldsFrom($request->all()), InventoryItem::rules())->validate();
        $item = InventoryItem::createFrom($data, $this->user()->id);

        return Response::structured(InventoryData::payload($item->fresh()));
    }
}
