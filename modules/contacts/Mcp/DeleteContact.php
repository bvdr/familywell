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

    protected string $description = 'Use this when the user wants to remove a person from the address book. Their contact methods and anything other modules keep on them (e.g. their birthday) go too. Everything goes to the trash and can be restored for 30 days with contacts_restore.';

    protected string $title = 'Delete contact';

    public function schema(JsonSchema $schema): array
    {
        return ['person_id' => $schema->integer()->description('Contact id, from contacts_list.')->required()];
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
