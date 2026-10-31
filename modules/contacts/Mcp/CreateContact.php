<?php

namespace Modules\Contacts\Mcp;

use App\Mcp\Tool;
use App\Support\Modules\Hooks;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Modules\Contacts\Person;

class CreateContact extends Tool
{
    protected string $module = 'contacts';

    protected string $access = 'write';

    protected string $name = 'contacts_create';

    protected string $description = 'Use this when the user wants to add a person to the family address book, optionally with phone numbers, emails, addresses, links or other contact methods. Look the name up with contacts_list beforehand when a duplicate is possible.';

    protected string $title = 'Create contact';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The person\'s name.')->required(),
            'contact_methods' => ContactData::methodsSchema($schema)->description('Phone numbers, emails, addresses, links or other ways to reach them.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(ContactData::rules(creating: true));

        // Same steps as ContactsController::store.
        $person = Person::create(['name' => $data['name'], 'created_by' => $this->user()->id]);
        $person->syncContactMethods($data['contact_methods'] ?? []);
        app(Hooks::class)->fire('person.saved', $person, ContactData::hookRequest($data));

        return Response::structured(ContactData::summary($person->load('contactMethods')));
    }
}
