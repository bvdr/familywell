<?php

namespace Modules\Contacts\Mcp;

use App\Mcp\Tool;
use App\Support\Modules\Hooks;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Modules\Contacts\Person;

#[IsDestructive]
class DeleteContact extends Tool
{
    protected string $module = 'contacts';

    protected string $access = 'write';

    protected string $name = 'contacts_delete';

    protected string $description = 'Move a contact to the trash with their phone numbers, emails and anything other modules keep on them (e.g. their birthday). Restorable for 30 days with contacts_restore.';

    public function schema(JsonSchema $schema): array
    {
        return ['person_id' => $schema->integer()->required()];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $person = Person::find((int) $request->get('person_id'));
        if (! $person) {
            return $this->notFound('Contact');
        }
        // Same as the web delete: let extension modules clean up first, then the row (methods cascade).
        app(Hooks::class)->fire('person.deleted', $person, ContactData::hookRequest([]));
        $person->delete();

        return Response::structured(['deleted' => true, 'person_id' => $person->id, 'name' => $person->name]);
    }
}
