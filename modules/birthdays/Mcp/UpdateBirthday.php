<?php

namespace Modules\Birthdays\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Modules\Birthdays\BirthdaysController;

#[IsIdempotent]
#[IsDestructive]
class UpdateBirthday extends Tool
{
    protected string $module = 'birthdays';

    protected string $access = 'write';

    protected string $name = 'birthdays_update';

    protected string $description = 'Use this when the user wants to correct a birthday\'s date. Month and day are always required; leaving out birth_year makes it year-less. Does not rename the person (that is contacts_update).';

    protected string $title = 'Update birthday';

    public function schema(JsonSchema $schema): array
    {
        return ['birthday_id' => $schema->integer()->description('Birthday id, from birthdays_list.')->required(), ...BirthdayData::schema($schema)];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(BirthdayData::rules(['birthday_id' => ['required', 'integer']]));
        $birthday = BirthdayData::birthday($data['birthday_id']);
        if (! $birthday) {
            return $this->notFound('Birthday');
        }

        // Same as BirthdaysController::update (which, like this, does not fire person.saved).
        $birthday->update(BirthdaysController::attributesFromRequest(BirthdayData::request($data)));

        return Response::structured(BirthdayData::payload($birthday));
    }
}
