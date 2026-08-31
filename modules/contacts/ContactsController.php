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
            'people' => $people->map(fn (Person $p) => $this->personPayload($p))->values(),
            'contactTypes' => ContactMethod::TYPES,
        ]);
    }

    /**
     * The `person` primitive's canonical payload. Its own fields are name + contact methods; the
     * `person.serialize` filter lets modules add more (Birthdays adds birthdate, age, next birthday).
     * With no listener the array is returned unchanged.
     */
    private function personPayload(Person $person): array
    {
        return $this->hooks->filter('person.serialize', [
            'id' => $person->id,
            'name' => $person->name,
            'contactMethods' => $person->contactMethods->map(fn (ContactMethod $m) => [
                'id' => $m->id,
                'type' => $m->type,
                'label' => $m->label,
                'value' => $m->value,
            ])->values(),
        ], $person);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatePerson($request, creating: true);

        $person = Person::create([
            'name' => $data['name'],
            'created_by' => $request->user()->id,
        ]);
        $this->syncContactMethods($person, $data['contact_methods'] ?? []);

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
            $this->syncContactMethods($person, $data['contact_methods'] ?? []);
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

    private function validatePerson(Request $request, bool $creating): array
    {
        return $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'contact_methods' => ['sometimes', 'array'],
            'contact_methods.*.type' => ['required', 'string', 'in:'.implode(',', ContactMethod::TYPES)],
            'contact_methods.*.label' => ['nullable', 'string', 'max:60'],
            'contact_methods.*.value' => ['required', 'string', 'max:255'],
        ]);
    }

    // Replace the person's contact methods with the submitted set (simple + correct for a small list).
    private function syncContactMethods(Person $person, array $methods): void
    {
        $person->contactMethods()->delete();
        foreach (array_values($methods) as $i => $m) {
            $person->contactMethods()->create([
                'type' => $m['type'],
                'label' => $m['label'] ?? null,
                'value' => $m['value'],
                'position' => $i,
            ]);
        }
    }
}
