<?php

namespace Modules\Contacts;

use App\Support\Tenancy\BelongsToFamily;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A way to reach a person: a phone number, email, address, link, etc. A person can have many.
 * Owned by the contacts module (core person data, not an extension).
 */
class ContactMethod extends Model
{
    use BelongsToFamily;

    protected $table = 'contacts_contact_methods';

    protected $fillable = ['person_id', 'type', 'label', 'value', 'position', 'family_id'];

    /** The kinds the editor offers. Kept in sync with the contacts page UI. */
    public const TYPES = ['phone', 'email', 'address', 'url', 'other'];

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
