<?php

namespace Modules\Contacts;

use App\Models\User;
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
}
