<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// A Google / Apple identity linked to a user (see App\Support\Auth\SocialLogin).
class SocialAccount extends Model
{
    protected $fillable = ['provider', 'provider_user_id', 'email'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
