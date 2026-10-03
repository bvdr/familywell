<?php

namespace Modules\Birthdays\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Modules\Birthdays\BirthdayDate;

class AddBirthday extends Tool
{
    protected string $module = 'birthdays';

    protected string $access = 'write';

    protected string $name = 'birthdays_add';

    protected string $description = 'Add (or set) someone\'s birthday by name. An existing contact with that exact name gets the birthday; otherwise a new contact is created.';

    public function schema(JsonSchema $schema): array
    {
        return ['name' => $schema->string()->required(), ...BirthdayData::schema($schema)];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate(BirthdayData::rules(['name' => ['required', 'string', 'max:255']]));
        $birthday = BirthdayDate::addFor($data['name'], BirthdayData::request($data), $this->user());

        return Response::json(BirthdayData::payload($birthday->fresh('person')));
    }
}
