<?php

namespace Modules\Birthdays\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Modules\Birthdays\BirthdaysController;

#[IsIdempotent]
class UpdateBirthday extends Tool
{
    protected string $module = 'birthdays';

    protected string $access = 'write';

    protected string $name = 'birthdays_update';

    protected string $description = 'Change a birthday\'s date. Month and day are always required; omit birth_year to make it year-less. To rename the person use contacts_update.';

    public function schema(JsonSchema $schema): array
    {
        return ['birthday_id' => $schema->integer()->required(), ...BirthdayData::schema($schema)];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate(BirthdayData::rules(['birthday_id' => ['required', 'integer']]));
        $birthday = BirthdayData::birthday($data['birthday_id']);
        if (! $birthday) {
            return $this->notFound('Birthday');
        }

        // Same as BirthdaysController::update (which, like this, does not fire person.saved).
        $birthday->update(BirthdaysController::attributesFromRequest(BirthdayData::request($data)));

        return Response::json(BirthdayData::payload($birthday));
    }
}
