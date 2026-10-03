<?php

namespace Modules\Contacts;

use App\Models\User;
use App\Support\Modules\Hooks;
use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The `person` primitive: a human in the family's address book. Its own data is just a name and a
 * set of contact methods; everything else (a birthdate + age, project links, …) is added by other
 * modules through the person.* hooks the ContactsController fires. This is the first primitive a
 * module introduces rather than extends — see docs/primitives-and-plugins.md §4.
 */
class Person extends Model
{
    use BelongsToFamily;

    protected $table = 'contacts_people';

    protected $fillable = ['name', 'family_id', 'created_by'];

    public function contactMethods(): HasMany
    {
        return $this->hasMany(ContactMethod::class)->orderBy('position')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Validation for a person write. Lives here (not in the controller) so the web form and the MCP
     * contacts tools apply the exact same rules. Lenient on update: an update may carry only a module's
     * extension field (e.g. Birthdays' birth_month), so name is `sometimes` there.
     */
    public static function rules(bool $creating): array
    {
        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'contact_methods' => ['sometimes', 'array'],
            'contact_methods.*.type' => ['required', 'string', 'in:'.implode(',', ContactMethod::TYPES)],
            'contact_methods.*.label' => ['nullable', 'string', 'max:60'],
            'contact_methods.*.value' => ['required', 'string', 'max:255'],
        ];
    }

    // Replace the person's contact methods with the submitted set (simple + correct for a small list).
    public function syncContactMethods(array $methods): void
    {
        $this->contactMethods()->delete();
        foreach (array_values($methods) as $i => $m) {
            $this->contactMethods()->create([
                'type' => $m['type'],
                'label' => $m['label'] ?? null,
                'value' => $m['value'],
                'position' => $i,
            ]);
        }
    }

    /**
     * The `person` primitive's canonical payload (web page + MCP). Its own fields are name + contact
     * methods; the `person.serialize` filter lets modules add more (Birthdays adds birthdate, age, next
     * birthday). With no listener the array is returned unchanged.
     */
    public function payload(): array
    {
        return app(Hooks::class)->filter('person.serialize', [
            'id' => $this->id,
            'name' => $this->name,
            'contactMethods' => $this->contactMethods->map(fn (ContactMethod $m) => [
                'id' => $m->id,
                'type' => $m->type,
                'label' => $m->label,
                'value' => $m->value,
            ])->values(),
        ], $this);
    }
}
