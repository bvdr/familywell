<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

// The unguessable link to a guest-shared entity. Anyone with the token reaches the entity (no login);
// an optional password adds a second gate. The token is a bearer secret, revocable by deleting the row.
class GuestLink extends Model
{
    protected $fillable = ['guestable_type', 'guestable_id', 'token', 'password', 'created_by'];

    // The hash must never reach the client; expose only whether a password is set (hasPassword()).
    protected $hidden = ['password'];

    protected static function booted(): void
    {
        static::creating(fn (GuestLink $link) => $link->token ??= Str::random(48));
    }

    public function guestable(): MorphTo
    {
        return $this->morphTo();
    }

    public function hasPassword(): bool
    {
        return filled($this->password);
    }
}
