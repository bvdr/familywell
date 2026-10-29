<?php

namespace Modules\Birthdays\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Modules\Birthdays\BirthdayDate;

#[IsDestructive]
class DeleteBirthday extends Tool
{
    protected string $module = 'birthdays';

    protected string $access = 'write';

    protected string $name = 'birthdays_delete';

    protected string $description = 'Use this when the user wants to remove a birthday. The contact itself is kept. The birthday goes to the trash and can be restored for 30 days with birthdays_restore.';

    protected string $title = 'Delete birthday';

    public function schema(JsonSchema $schema): array
    {
        return ['birthday_id' => $schema->integer()->description('Birthday id, from birthdays_list.')->required()];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $birthday = BirthdayDate::find((int) $request->get('birthday_id'));
        if (! $birthday) {
            return $this->notFound('Birthday');
        }
        $birthday->delete(); // same as the web delete

        return Response::structured(['deleted' => true, 'birthday_id' => $birthday->id]);
    }
}
