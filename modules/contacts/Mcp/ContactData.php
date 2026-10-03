<?php

namespace Modules\Contacts\Mcp;

use Illuminate\Http\Request;
use Modules\Contacts\ContactMethod;
use Modules\Contacts\Person;

// Shared lookups + payload shapes for the Contacts MCP tools. The address book is family-wide (no
// per-person sharing: every member sees and edits the same people), so the BelongsToFamily scope under
// CurrentFamily IS the access rule.
class ContactData
{
    public static function person(mixed $id): ?Person
    {
        return Person::find((int) $id);
    }

    public static function summary(Person $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            // No ids: an update replaces the whole set (same as the web editor), so they're not needed.
            'contact_methods' => $p->contactMethods->map(fn (ContactMethod $m) => [
                'type' => $m->type,
                'label' => $m->label,
                'value' => $m->value,
            ])->values(),
            // The contacts page has no per-person route; link to the address book.
            'url' => rtrim((string) config('app.url'), '/').'/m/contacts',
        ];
    }

    /**
     * The person.* hooks take an HTTP request (listeners read their own fields from it, e.g. Birthdays'
     * birth_month). An MCP call has none, so hand them one built from the validated tool arguments; a
     * listener whose fields aren't there stays inert, exactly as for a web save without them.
     */
    public static function hookRequest(array $data): Request
    {
        return Request::create('/', 'POST', $data);
    }

    /** Validation for a contacts write: Person::rules (shared with the web form) + the tool's own keys. */
    public static function rules(bool $creating): array
    {
        return Person::rules($creating) + ($creating ? [] : ['person_id' => ['required', 'integer']]);
    }

    public static function methodsSchema($schema)
    {
        return $schema->array()->items($schema->object([
            'type' => $schema->string()->enum(ContactMethod::TYPES)->required(),
            'label' => $schema->string()->description('Optional, e.g. "work", "home".'),
            'value' => $schema->string()->required(),
        ]));
    }
}
