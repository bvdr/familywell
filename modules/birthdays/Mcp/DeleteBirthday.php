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

    protected string $description = 'Remove a birthday (the id from birthdays_list). The contact itself stays. Restorable for 30 days with birthdays_restore.';

    public function schema(JsonSchema $schema): array
    {
        return ['birthday_id' => $schema->integer()->required()];
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
