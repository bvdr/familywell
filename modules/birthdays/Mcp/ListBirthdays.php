<?php

namespace Modules\Birthdays\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Birthdays\BirthdayDate;

// List and "upcoming" in one tool (ponytail): upcoming is just the soonest-first list cut at a window.
#[IsReadOnly]
class ListBirthdays extends Tool
{
    protected string $module = 'birthdays';

    protected string $name = 'birthdays_list';

    protected ?string $app = 'birthdays';

    protected string $description = 'The family\'s birthdays, soonest first, with the next date, days until it, and the age they turn (when the year is known). Pass within_days for upcoming ones only (e.g. 30).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'within_days' => $schema->integer()->description('Only birthdays in the next N days (0 = today only). Omit for all.'),
            ...$this->pageSchema($schema),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate(['within_days' => ['nullable', 'integer', 'min:0']]);
        $within = $request->get('within_days');
        // Same soonest-first list as the Birthdays page; filtered in PHP because days-until is derived.
        $all = BirthdayDate::soonestFirst()
            ->when($within !== null, fn ($c) => $c->filter(fn (BirthdayDate $b) => $b->daysUntil() <= (int) $within)->values());
        $page = $this->page($all, $request);

        return Response::json([
            'birthdays' => $page['items']->map(fn (BirthdayDate $b) => BirthdayData::payload($b))->values(),
            'next_cursor' => $page['next_cursor'],
        ]);
    }
}
