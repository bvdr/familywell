<?php

namespace Modules\Contacts;

use App\Http\Controllers\Controller;
use App\Support\Modules\Hooks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContactsController extends Controller
{
    public function __construct(private Hooks $hooks) {}

    public function index(Request $request): Response
    {
        // Behavior hook `person.query`: a module may sort/filter the people list (e.g. birthdays by
        // upcoming). Inert with no listener. Table-qualified order so a module JOIN stays unambiguous.
        $query = Person::query()->with('contactMethods')->select('contacts_people.*');
        $query = $this->hooks->filter('person.query', $query, $request);
        $people = $query->orderBy('contacts_people.name')->orderBy('contacts_people.id')->get();

        return Inertia::render('contacts/index', [
            'people' => $people->map(fn (Person $p) => $p->payload())->values(),
            'contactTypes' => ContactMethod::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatePerson($request, creating: true);

        $person = Person::create([
            'name' => $data['name'],
            'created_by' => $request->user()->id,
        ]);
        $person->syncContactMethods($data['contact_methods'] ?? []);

        // Behavior hook `person.saved`: modules persist their own per-person data from the request
        // (Birthdays stores the birthdate). Inert with no listener.
        $this->hooks->fire('person.saved', $person, $request);

        return back();
    }

    public function update(Request $request, Person $person): RedirectResponse
    {
        // Lenient on update: a module's extension field (e.g. Birthdays' `birthdate`) may arrive on its
        // own via a slot's onPatch, with no name/contact_methods. Only touch what the request carries —
        // otherwise a birthday-only patch would fail name validation or wipe the contact methods.
        $data = $this->validatePerson($request, creating: false);

        if (array_key_exists('name', $data)) {
            $person->update(['name' => $data['name']]);
        }
        if ($request->has('contact_methods')) {
            $person->syncContactMethods($data['contact_methods'] ?? []);
        }

        $this->hooks->fire('person.saved', $person, $request);

        return back();
    }

    public function destroy(Request $request, Person $person): RedirectResponse
    {
        $this->hooks->fire('person.deleted', $person, $request);
        $person->delete(); // cascades to contact methods + any module extension rows

        return back();
    }

    // Rules live on Person so the MCP contacts tools validate exactly like this form.
    private function validatePerson(Request $request, bool $creating): array
    {
        return $request->validate(Person::rules($creating));
    }
}
