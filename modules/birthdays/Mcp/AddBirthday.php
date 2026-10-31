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
class AddBirthday extends Tool
{
    protected string $module = 'birthdays';

    protected string $access = 'write';

    protected string $name = 'birthdays_add';

    protected string $description = 'Use this when the user wants to save someone\'s birthday. If a contact with exactly that name exists, the birthday is set on it; otherwise a new contact with that name is created.';

    protected string $title = 'Add birthday';

    public function schema(JsonSchema $schema): array
    {
        return ['name' => $schema->string()->description('The person\'s full name, e.g. "Ana Popescu".')->required(), ...BirthdayData::schema($schema)];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(BirthdayData::rules(['name' => ['required', 'string', 'max:255']]));
        $birthday = BirthdayDate::addFor($data['name'], BirthdayData::request($data), $this->user());

        return Response::structured(BirthdayData::payload($birthday->fresh('person')));
    }
}
