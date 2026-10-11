<?php

namespace Modules\Spending\Mcp;

use App\Mcp\Tool;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Modules\Notifications\Notify;
use Modules\Projects\Mcp\ProjectData;
use Modules\Projects\ProjectLinkables;
use Modules\Spending\SpendEvent;
use Modules\Spending\SpendingController;
use Modules\Spending\SpendSetting;

class CreateList extends Tool
{
    protected string $module = 'spending';

    protected string $access = 'write';

    protected string $name = 'spending_create_list';

    protected string $description = 'Create a spending list (a trip, a budget, a project\'s costs). Everyone in the family takes part, like a list made in the app. Pass project_id to create it for a project and link it there in one step; such lists are kept out of monthly spending by default (count_in_monthly false) while still totalling their own expenses.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required(),
            'currency' => $schema->string()->enum(SpendingController::CURRENCIES)->description('Base currency of the list (default: the family\'s default currency).'),
            'project_id' => $schema->integer()->description('Project to link it to (needs projects access).'),
            'count_in_monthly' => $schema->boolean()->description('Do this list\'s expenses count in monthly spending? Default true, or false when project_id is given.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        // Same rules as SpendingController::storeEvent, plus the optional project link + monthly flag.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'currency' => ['nullable', 'in:'.implode(',', SpendingController::CURRENCIES)],
            'project_id' => ['nullable', 'integer'],
            'count_in_monthly' => ['nullable', 'boolean'],
        ]);
        $user = $this->user();
        $currency = $data['currency'] ?? (SpendSetting::defaultCurrency() ?? SpendingController::CURRENCIES[0]);
        $flag = isset($data['count_in_monthly']) ? (bool) $data['count_in_monthly'] : null;

        $project = null;
        if (! empty($data['project_id'])) {
            // Linking writes to a project, so the token must be allowed to change projects too.
            if (! $user->tokenCan('projects:write') || ! app(ModuleRegistry::class)->isEnabled('projects', (int) CurrentFamily::id())) {
                return Response::error('This connection has no write access to projects. Reconnect and allow it to link the list to a project.');
            }
            $project = ProjectData::project($user, $data['project_id']);
            if (! $project) {
                return $this->notFound('Project');
            }
        }

        $event = $project
            ? ProjectLinkables::createSpendList($project, $user, $data['name'], $currency, $flag)
            : SpendEvent::createFor($user, ['name' => $data['name'], 'currency' => $currency, 'count_in_monthly' => $flag ?? true]);

        Notify::toAccessOf($event, 'spending', $user->id, 'New spend tracker', "{$user->name} started: {$event->name}", "/m/spending/events/{$event->id}");
        SpendData::changed();

        return Response::structured([
            'id' => $event->id,
            'name' => $event->name,
            'currency' => $event->currency,
            'count_in_monthly' => (bool) $event->count_in_monthly,
            'project_id' => $project?->id,
            'url' => rtrim((string) config('app.url'), '/')."/m/spending/events/{$event->id}",
        ]);
    }
}
