<?php

namespace Modules\Contacts\Mcp;

use App\Mcp\Tool;
use App\Support\Modules\Hooks;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Modules\Contacts\Person;

class CreateContact extends Tool
{
    protected string $module = 'contacts';

    protected string $access = 'write';

    protected string $name = 'contacts_create';

    protected string $description = 'Add a person to the family address book, with optional contact methods (phone, email, address, url, other). Check contacts_list first to avoid duplicates.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required(),
            'contact_methods' => ContactData::methodsSchema($schema),
        ];
    }

    public function handle(Request $request): Response
    {
        $data = $request->validate(ContactData::rules(creating: true));

        // Same steps as ContactsController::store.
        $person = Person::create(['name' => $data['name'], 'created_by' => $this->user()->id]);
        $person->syncContactMethods($data['contact_methods'] ?? []);
        app(Hooks::class)->fire('person.saved', $person, ContactData::hookRequest($data));

        return Response::json(ContactData::summary($person->load('contactMethods')));
    }
}
