<?php

namespace Modules\Contacts\Mcp;

use App\Mcp\Tool;
use App\Support\Modules\Hooks;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[IsDestructive]
class UpdateContact extends Tool
{
    protected string $module = 'contacts';

    protected string $access = 'write';

    protected string $name = 'contacts_update';

    protected string $description = 'Rename a person and/or replace their contact methods. contact_methods, when given, is the COMPLETE new set (send the existing ones you want to keep, from contacts_get); omit it to leave them unchanged.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'person_id' => $schema->integer()->required(),
            'name' => $schema->string(),
            'contact_methods' => ContactData::methodsSchema($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(ContactData::rules(creating: false));
        $person = ContactData::person($data['person_id']);
        if (! $person) {
            return $this->notFound('Person');
        }

        // Same as ContactsController::update: only touch what the call carries.
        if (array_key_exists('name', $data)) {
            $person->update(['name' => $data['name']]);
        }
        if (array_key_exists('contact_methods', $data)) {
            $person->syncContactMethods($data['contact_methods']);
        }
        app(Hooks::class)->fire('person.saved', $person, ContactData::hookRequest($data));

        return Response::structured(ContactData::summary($person->load('contactMethods')));
    }
}
