<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

// A named participant in a guest-shared entity, with no user account. Reached only through the
// entity's guest link; the entity's owner sets each guest's role.
class Guest extends Model
{
    public const ROLES = ['viewer', 'editor'];

    protected $fillable = ['guestable_type', 'guestable_id', 'name', 'role'];

    public function guestable(): MorphTo
    {
        return $this->morphTo();
    }

    public function canEdit(): bool
    {
        return $this->role === 'editor';
    }
}
