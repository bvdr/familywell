<?php

namespace Modules\Contacts\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetContact extends Tool
{
    protected string $module = 'contacts';

    protected string $name = 'contacts_get';

    protected ?string $app = 'contact';

    protected string $description = 'Use this when the user asks for one person\'s details. Returns their contact methods plus what other enabled modules add (e.g. birthday, age, next birthday). Read-only.';

    protected string $title = 'Get contact';

    public function schema(JsonSchema $schema): array
    {
        return ['person_id' => $schema->integer()->description('Contact id, from contacts_list.')->required()];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $p = ContactData::person($request->get('person_id'));
        if (! $p) {
            return $this->notFound('Person');
        }

        // Extension fields come from the same person.serialize filter the web page uses, so a module's
        // data shows up here without the contacts module knowing about it (their keys keep the module's
        // own camelCase naming).
        return Response::structured(ContactData::summary($p) + Arr::except($p->payload(), ['id', 'name', 'contactMethods']));
    }
}
