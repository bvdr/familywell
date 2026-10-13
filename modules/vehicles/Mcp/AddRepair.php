<?php

namespace Modules\Vehicles\Mcp;

use App\Mcp\Tool;
use App\Support\Mcp\IncomingFile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsOpenWorld]
class AddRepair extends Tool
{
    protected string $module = 'vehicles';

    protected string $access = 'write';

    protected string $name = 'vehicles_add_repair';

    protected string $description = 'Record a repair (something fixed outside the regular maintenance schedule). Set add_expense=true (with a cost) to also record it in Spending, linked to the car. An invoice or photo can be attached (file / file_url / file_base64).';

    public function __construct()
    {
        $this->setMeta(IncomingFile::FILE_PARAMS_META); // lets ChatGPT pass a file the person attached
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'vehicle_id' => $schema->integer()->required(),
            'title' => $schema->string()->description('What was fixed, e.g. "Replaced rear brake pads".')->required(),
            'occurred_on' => $schema->string()->format('date')->description('YYYY-MM-DD (default: today).'),
            'mileage_km' => $schema->integer(),
            'cost' => $schema->number(),
            'provider' => $schema->string()->description('Garage / who fixed it.'),
            'notes' => $schema->string(),
            'add_expense' => $schema->boolean()->description('Also record the cost in Spending (default false, like the app).'),
            ...IncomingFile::schema($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'vehicle_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:120'],
            'occurred_on' => ['nullable', 'date'],
            'mileage_km' => ['nullable', 'integer', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'provider' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'add_expense' => ['sometimes', 'boolean'],
        ]);
        $v = VehicleData::vehicle($data['vehicle_id']);
        if (! $v) {
            return $this->notFound('Vehicle');
        }
        if (($data['add_expense'] ?? false) && ! VehicleData::spendingEnabled()) {
            return Response::error('Spending is not turned on for this family, so the repair cannot be recorded as an expense. Log it without add_expense.');
        }

        $file = IncomingFile::from($request); // validated (type, size) before anything is written
        $repair = $v->logRepair([
            'title' => $data['title'],
            'occurred_on' => $data['occurred_on'] ?? now()->toDateString(),
            'mileage' => $data['mileage_km'] ?? null,
            'cost' => $data['cost'] ?? null,
            'provider' => $data['provider'] ?? null,
            'notes' => $data['notes'] ?? null,
            'create_expense' => $data['add_expense'] ?? false,
        ], $this->user());

        if ($file) {
            $repair->attachFile($file);
        }

        return Response::structured([
            'repair_id' => $repair->id,
            'attachment' => $file?->getClientOriginalName(),
            'title' => $repair->title,
            'expense_recorded' => ($data['add_expense'] ?? false) && ! empty($data['cost']),
            'url' => VehicleData::url("/m/vehicles/{$v->id}/repairs"),
        ]);
    }
}
