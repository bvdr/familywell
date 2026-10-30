<?php

namespace Modules\Contacts\Mcp;

use App\Mcp\Tool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Modules\Contacts\Person;

// List and search in one tool (ponytail): a search is just the list with a filter, and the address book
// is family-sized.
#[IsReadOnly]
class ListContacts extends Tool
{
    protected string $module = 'contacts';

    protected string $name = 'contacts_list';

    protected string $description = 'Use this when the user asks for someone\'s phone, email or address, or wants to browse the family address book. Returns people A-Z with their contact methods; `query` searches names and every contact detail. Read-only.';

    protected string $title = 'List contacts';

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Text to match in the name or any phone/email/address/etc. (case-insensitive).'),
            ...$this->pageSchema($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $q = trim((string) $request->get('query', ''));
        $query = Person::query()->with('contactMethods')
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->whereLike('name', "%{$q}%")
                ->orWhereHas('contactMethods', fn ($m) => $m->whereLike('value', "%{$q}%"))))
            ->orderBy('name')->orderBy('id');
        $page = $this->page($query, $request);

        return Response::structured([
            'people' => $page['items']->map(fn (Person $p) => ContactData::summary($p))->values(),
            'next_cursor' => $page['next_cursor'],
        ]);
    }
}
